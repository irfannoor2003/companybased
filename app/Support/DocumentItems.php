<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shared logic for parsing request line-items, computing money totals
 * and syncing them to a document.
 *
 * Each document's items relation must be fillable with: product_id,
 * description, qty, unit_price, discount_percent, tax_percent, line_total.
 *
 * Note on precision: totals are PHP floats rounded to 2dp at each step, and the
 * schema stores them as decimal(14,2). Values are therefore safe for addition
 * and subtraction, but the inputs are parsed through (float) — this is a
 * convention, not arbitrary-precision arithmetic. Callers that need exact
 * decimal semantics should go through ext-bcmath.
 */
class DocumentItems
{
    /**
     * Sync line items onto the document. Returns ['subtotal', 'tax', 'total'].
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{subtotal: float, tax: float, total: float}
     */
    /**
     * The per-line money arithmetic, in one place.
     *
     * Every caller that has to price a line must use these exact rules, otherwise
     * the printed rows stop adding up to the printed total: subtotal is net of the
     * line discount, and tax is a percentage of that net rather than of the gross.
     *
     * Rounding is per-line and to two places. Doing it once per line and summing
     * the rounded figures is what keeps the header reconcilable; rounding only the
     * final sum drifts by a cent per line.
     *
     * @return array{line_subtotal: float, line_discount: float, line_net: float, line_tax: float}
     */
    public static function lineTotals(
        float $qty,
        float $unitPrice,
        float $discountPercent = 0,
        float $taxPercent = 0,
    ): array {
        $lineSubtotal = round($qty * $unitPrice, 2);
        $lineDiscount = round($lineSubtotal * ($discountPercent / 100), 2);
        $lineNet = round($lineSubtotal - $lineDiscount, 2);
        $lineTax = round($lineNet * ($taxPercent / 100), 2);

        return [
            'line_subtotal' => $lineSubtotal,
            'line_discount' => $lineDiscount,
            'line_net' => $lineNet,
            'line_tax' => $lineTax,
        ];
    }

    /**
     * Recompute a document's header totals from its persisted line items.
     *
     * Used when the lines are edited in place rather than replaced -- a currency
     * conversion, for instance -- so the header is derived by the same arithmetic
     * sync() uses instead of being multiplied independently. Multiplying subtotal,
     * tax and total separately is what produced documents where
     * subtotal + tax != total.
     *
     * @return array{subtotal: float, tax: float, total: float}
     */
    public static function totalsFromPersistedItems(Model $document): array
    {
        $subtotal = 0.0;
        $tax = 0.0;

        foreach ($document->items()->get() as $item) {
            $totals = self::lineTotals(
                (float) $item->qty,
                (float) $item->unit_price,
                (float) ($item->discount_percent ?? 0),
                (float) ($item->tax_percent ?? 0),
            );

            $subtotal += $totals['line_net'];
            $tax += $totals['line_tax'];
        }

        $subtotal = round($subtotal, 2);
        $tax = round($tax, 2);

        return [
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => round($subtotal + $tax, 2),
        ];
    }

    /**
     * Line columns that only some document types have.
     *
     * `account_id` exists on purchase bill lines alone -- it is the expense account
     * the line is charged to on the P&L. Emitting it unconditionally would fail on
     * the eight other line tables that have no such column, so it is added only for
     * a document whose items table actually carries it.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function optionalColumns(Model $document, array $item): array
    {
        // A bill line records which expense account it is charged to.
        if ($document instanceof \App\Models\PurchaseInvoice) {
            return ['account_id' => ! empty($item['account_id']) ? (int) $item['account_id'] : null];
        }

        // A sales line snapshots the product's CURRENT weighted-average cost, so
        // cost of goods sold is recognised at posting time and later repricing
        // cannot rewrite a past invoice's margin.
        //
        // The average, not `products.cost_price`. That column is a figure someone
        // typed onto the product, and it is only right if every purchase of the
        // product happened at the same price. Buying the same item at 100 and then
        // at 140 leaves stock worth neither, and the margin reported on a sale in
        // between is a fiction.
        //
        // An explicitly supplied value still wins, so re-saving a historical
        // invoice reproduces the figure it originally carried.
        if ($document instanceof \App\Models\SalesInvoice) {
            $unitCost = $item['unit_cost'] ?? null;

            if ($unitCost === null && ! empty($item['product_id'])) {
                $unitCost = app(\App\Services\Inventory\ValuationService::class)
                    ->costForProduct((int) $item['product_id']);
            }

            return ['unit_cost' => $unitCost === null ? null : (float) $unitCost];
        }

        return [];
    }

    public static function sync(Model $document, array $items): array
    {
        $cleaned = [];
        $subtotal = 0.0;
        $tax = 0.0;

        foreach ($items as $item) {
            $qty = (float) ($item['qty'] ?? 1);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $discount = (float) ($item['discount_percent'] ?? 0);
            $taxPercent = (float) ($item['tax_percent'] ?? 0);
            $description = trim((string) ($item['description'] ?? ''));
            $numericFields = [$item['qty'] ?? 1, $item['unit_price'] ?? 0, $item['discount_percent'] ?? 0, $item['tax_percent'] ?? 0];

            if ($description === '' || count(array_filter($numericFields, fn ($value) => ! is_numeric($value))) > 0 || $qty <= 0 || $unitPrice < 0 || $discount < 0 || $discount > 100 || $taxPercent < 0 || $taxPercent > 100) {
                throw ValidationException::withMessages([
                    'items' => 'Each item must have a description, a positive quantity, a non-negative price, and discount and tax percentages between 0 and 100.',
                ]);
            }

            $totals = self::lineTotals($qty, $unitPrice, $discount, $taxPercent);

            $cleaned[] = [
                'product_id' => ! empty($item['product_id']) ? $item['product_id'] : null,
                'description' => $description,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'discount_percent' => $discount,
                'tax_percent' => $taxPercent,
                'line_total' => $totals['line_net'],
            ] + self::optionalColumns($document, $item);

            $subtotal += $totals['line_net'];
            $tax += $totals['line_tax'];
        }

        // Delete-then-insert is two statements, so a constraint violation on the
        // insert would otherwise leave the document with zero line items while
        // the caller still writes its header totals. Wrapping it here makes every
        // one of the ~44 callsites atomic without each controller repeating it.
        DB::transaction(function () use ($document, $cleaned) {
            $document->items()->delete();
            $document->items()->createMany($cleaned);
        });

        $subtotal = round($subtotal, 2);
        $tax = round($tax, 2);

        return [
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => round($subtotal + $tax, 2),
        ];
    }
}
