<?php

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Weighted-average inventory valuation.
 *
 * Recognises cost of goods sold and values closing stock at a moving average of
 * what the business actually paid, rather than at a `cost_price` typed onto the
 * product record.
 *
 *     On a receipt of Q units at cost C, against Q0 on hand at average A:
 *
 *         A' = (Q0 x A + Q x C) / (Q0 + Q)
 *
 *     On a sale, the average is unchanged. Q units leave at A and the same A is
 *     credited to Inventory, because weighted average does not consume cost layers.
 *     There is consequently no per-layer state, and nothing that can drift out of
 *     step with the stock rows the way a FIFO layer table can.
 *
 * The average lives on the inventory item, not the product, because that is the
 * level stock is tracked at and a product may sit in several warehouses.
 */
class ValuationService
{
    /**
     * The cost at which the next unit leaves stock.
     *
     * Falls back to the product's `cost_price` for an item that has never been
     * received through a tracked movement, so a business that set up its catalogue
     * before this existed keeps valuing that stock rather than valuing it at zero.
     */
    public function averageCost(?InventoryItem $item): ?string
    {
        if ($item === null) {
            return null;
        }

        $average = $item->moving_average_cost;

        if ($average !== null && (float) $average > 0) {
            return Money::round((string) $average, 4);
        }

        $fallback = Product::where('id', $item->product_id)->value('cost_price');

        return $fallback === null ? null : (string) $fallback;
    }

    /**
     * The cost of one unit, for a product, looked up through its inventory item.
     *
     * Falls back to the product's `cost_price` when there is no inventory item at
     * all. A product can be costed for margin without being stock-tracked, and
     * requiring an inventory row silently zeroed its cost of goods sold -- the
     * invoices still balanced, they just reported every sale at a 100% margin.
     *
     * A service line has neither, so it correctly yields null: there is no cost of
     * goods to recognise for something that was never stocked.
     */
    public function costForProduct(?int $productId): ?string
    {
        if (! $productId) {
            return null;
        }

        $average = $this->averageCost(InventoryItem::where('product_id', $productId)->first());

        if ($average !== null) {
            return $average;
        }

        $fallback = Product::where('id', $productId)->value('cost_price');

        return $fallback === null ? null : (string) $fallback;
    }

    /**
     * Fold a receipt into the item's moving average.
     *
     * Only ever called for stock coming IN. An issue out leaves the average alone,
     * which is the defining property of the method.
     *
     * `$quantityOnHandBefore` is the quantity the item had BEFORE this receipt, and
     * it is passed in rather than read here for a reason that produced a badly
     * wrong first calculation: `adjust()` writes the stock row before calling this,
     * so re-reading the quantity found the incoming units already counted as
     * existing stock. Receiving 10 at 100 against nothing set the average to 50
     * instead of 100, and every figure derived from it was half what it should be.
     *
     * The recalculation runs with the item row locked, because two receipts landing
     * together and both reading the same quantity on hand would each compute an
     * average that is right for neither.
     *
     * @param  string  $quantityIn  units received; must be positive
     * @param  string|null  $unitCost  what they cost; null leaves the average unchanged
     * @param  string  $quantityOnHandBefore  units already on the shelf
     */
    public function recordReceipt(
        int $itemId,
        string $quantityIn,
        ?string $unitCost,
        string $quantityOnHandBefore = '0',
    ): void {
        $incoming = (float) $quantityIn;

        if ($incoming <= 0) {
            return;
        }

        // A receipt with no cost cannot move the average: weighting it in at zero
        // would write off the value of everything already on the shelf.
        if ($unitCost === null || (float) $unitCost < 0) {
            return;
        }

        DB::transaction(function () use ($itemId, $incoming, $unitCost, $quantityOnHandBefore) {
            $item = InventoryItem::query()->whereKey($itemId)->lockForUpdate()->first();

            if ($item === null) {
                return;
            }

            $onHand = (float) $quantityOnHandBefore;
            $current = (float) ($item->moving_average_cost ?? 0);

            /**
             * First ever receipt, or stock that has run to zero.
             *
             * With nothing on the shelf there is nothing to blend with, so the
             * incoming price simply becomes the average. Averaging against a stale
             * average at zero quantity would dilute the new price towards whatever
             * the last one happened to be.
             */
            if ($onHand <= 0) {
                $item->forceFill(['moving_average_cost' => Money::round((string) $unitCost, 4)])->save();

                return;
            }

            $blended = (($onHand * $current) + ($incoming * (float) $unitCost)) / ($onHand + $incoming);

            $item->forceFill(['moving_average_cost' => Money::round((string) $blended, 4)])->save();
        });
    }

    /**
     * The total value of an item's stock at its current average.
     *
     * This is what belongs on the balance sheet. It is deliberately computed rather
     * than stored, so it cannot disagree with the quantity rows it is derived from.
     */
    public function stockValue(int $itemId): string
    {
        $item = InventoryItem::find($itemId);

        if ($item === null) {
            return '0.00';
        }

        $quantity = \App\Support\InventoryLedger::onHand($itemId);
        $cost = $this->averageCost($item) ?? '0';

        return Money::multiply($cost, $quantity);
    }
}
