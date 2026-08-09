<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared logic for parsing request line-items, computing money totals
 * (always decimal/round, never floats) and syncing them to a document.
 *
 * Each document's items relation must be fillable with: product_id,
 * description, qty, unit_price, discount_percent, tax_percent, line_total.
 */
class DocumentItems
{
    /**
     * Sync line items onto the document. Returns ['subtotal', 'tax', 'total'].
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{subtotal: float, tax: float, total: float}
     */
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

            if ($description === '' || $qty <= 0) {
                continue;
            }

            $lineSubtotal = round($qty * $unitPrice, 2);
            $lineDiscount = round($lineSubtotal * ($discount / 100), 2);
            $lineNet = round($lineSubtotal - $lineDiscount, 2);
            $lineTax = round($lineNet * ($taxPercent / 100), 2);

            $cleaned[] = [
                'product_id' => ! empty($item['product_id']) ? $item['product_id'] : null,
                'description' => $description,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'discount_percent' => $discount,
                'tax_percent' => $taxPercent,
                'line_total' => $lineNet,
            ];

            $subtotal += $lineNet;
            $tax += $lineTax;
        }

        $document->items()->delete();
        $document->items()->createMany($cleaned);

        $subtotal = round($subtotal, 2);
        $tax = round($tax, 2);

        return [
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => round($subtotal + $tax, 2),
        ];
    }
}
