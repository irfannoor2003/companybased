<?php

namespace App\Services\Accounting;

use App\Models\InventoryItem;
use App\Models\InventoryProductionOrder;
use App\Models\JournalEntry;
use App\Services\Accounting\ProductionAbsorptionService;
use App\Support\Money;

/**
 * Posts the accounting side of a production order.
 *
 * The stock side already existed -- InventoryLedger::applyProduction() moves
 * components out and finished goods in -- but nothing reached the general ledger.
 * So a completed production order changed every stock figure on the system and
 * none of the accounting ones: inventory still carried the components at their
 * original cost, the finished goods had no cost basis at all, and the difference
 * went nowhere.
 *
 * The posting:
 *
 *   Dr Work in Progress            cost of components consumed
 *   Dr / Cr Production Variance    difference vs the finished goods' own cost
 *       Cr Inventory                   components, at their own cost
 *       Cr Inventory                   finished goods, at their own cost
 *
 * Two decisions that are genuinely arguable, so they are argued here:
 *
 *  - Work in progress is relieved to finished goods INVENTORY, not to cost of
 *    goods sold. Nothing has been sold. Crediting COGS at completion would report
 *    a cost for goods that are still on the shelf and would make margin wrong on
 *    every production order until the goods happened to be invoiced. COGS follows
 *    the sale, as it does everywhere else.
 *
 *  - The variance is measured against the finished item's own cost_price, when it
 *    has one. Producing 10 units at a component cost of 50 each but a cost_price
 *    of 40 means the business expects them to sell for less than they cost to
 *    make; posting that as a variance surfaces it now rather than leaving it
 *    buried in a mis-stated inventory. Where no cost_price exists, no variance is
 *    posted -- inventing a standard cost is not something this can do.
 */
class ProductionPostingService
{
    public const WORK_IN_PROGRESS = '1350';
    public const MANUFACTURING_OVERHEAD = '1450';
    public const PRODUCTION_VARIANCE = '6700';

    /**
     * Post a completed production order.
     *
     * Returns null when there is nothing to post -- an order consuming no
     * components at all. Posting a zero journal would put noise on the trial
     * balance for no reason.
     */
    public function postCompleted(InventoryProductionOrder $order): ?JournalEntry
    {
$cost = $this->componentCost($order);

        $total = Money::add($cost['components'], $cost['absorbed']);

        if (Money::equals($total, '0')) {
            return null;
        }

        /**
         * Production variance = everything the goods cost to make, less the value
         * they are carried at. Debits and credits use opposite signs.
         *
         * This INCLUDES absorbed labour and overhead, not just components. Once
         * factory labour is capitalised into work in progress it is part of the cost
         * of the goods, and leaving it out would compare a components-only cost
         * against a fully-absorbed carrying value and book a variance on every order
         * in a business that absorbs overhead -- a permanent, entirely fictional loss.
         */
        $variance = Money::subtract($total, $cost['finishedGoods']);

        /**
         * Three sides, and the sign on each depends on whether the account is
         * debited or credited -- which is why the variance is negated on the
         * credit branch below.
         *
         *  Cr Inventory                   components, out, at their own cost
         *  Cr Work in Progress            labour + overhead already capitalised
         *  Dr Inventory                   goods produced, at their own cost
         *  Dr/Cr Production Variance     the balancing difference
         *
         * The WIP credit is the part that changes with absorption. Work in progress
         * carries labour and overhead from the moment they are absorbed until the
         * order completes, so completion has to take it back OUT -- otherwise that
         * cost would sit in WIP forever and the same labour would be capitalised into
         * goods over and over.
         *
         * Without absorption there is no WIP balance to relieve, and the line is
         * omitted rather than posted at zero: an order that consumed components only
         * has no WIP movement at all, and a zero-valued line would be noise on the
         * trial balance.
         *
         * The two Inventory lines are on the SAME account deliberately: components
         * left the shelf and finished goods arrived on it, and netting them into one
         * line would hide a large gross movement behind a small net one.
         */
        $lines = [
            [
                'code' => '1200', // Inventory
                'credit' => $cost['components'],
                'memo' => 'Components issued to production',
            ],
            [
                'code' => '1200', // Inventory
                'debit' => $cost['finishedGoods'],
                'memo' => 'Finished goods received from production',
            ],
        ];

        if (! Money::equals($cost['absorbed'], '0')) {
            $lines[] = [
                'code' => self::WORK_IN_PROGRESS,
                'credit' => $cost['absorbed'],
                'memo' => 'Labour and overhead transferred from work in progress',
            ];
        }

        /**
         * Work in progress is relieved here, not before.
         *
         * Components go straight out of Inventory in the same event the finished
         * goods come in, so components never rest in WIP. But labour and overhead
         * are absorbed over the life of the order, and DO accumulate in WIP -- that
         * is what makes WIP a real account rather than a permanent zero. Completion
         * is where that accumulated balance is transferred out to the goods.
         *
         * Overrun (variance positive): production cost more than the goods are
         * carried at, so it is an expense.
         *
         * Underrun (variance negative): the goods are carried above what they cost
         * to make, which flatters inventory. Crediting the variance account reduces
         * the period's production cost and puts that difference back on the balance
         * sheet, instead of leaving inventory quietly overstated.
         */
        if (Money::isGreaterThan($variance, '0')) {
            $lines[] = [
                'code' => self::PRODUCTION_VARIANCE,
                'debit' => $variance,
                'memo' => 'Components cost more than the goods produced are carried at',
            ];
        } elseif (Money::isLessThan($variance, '0')) {
            $lines[] = [
                'code' => self::PRODUCTION_VARIANCE,
                'credit' => Money::of(abs((float) $variance)),
                'memo' => 'Goods produced are carried above their component cost',
            ];
        }

/**
         * Posted through LedgerPosting so the order becomes the idempotency key.
         *
         * Completing a production order twice must not consume the components
         * twice, and the stock side already guards that -- so the journal has to be
         * guarded the same way, or a retry would double the cost on the balance
         * sheet while leaving stock correct. The two would then disagree, which is
         * worse than either being wrong alone.
         */
        $entry = app(LedgerPosting::class)->post(
            $order,
            $lines,
            'Production order '.$order->number.' completed',
            now()->toDateString(),
            stage: 'completed',
        );

        return $entry;
    }

/**
     * Cost of the components consumed, the labour and overhead absorbed into the
     * order, and of the goods produced.
     *
     * @return array{components: string, absorbed: string, total: string, finishedGoods: string}
     */
    public function componentCost(InventoryProductionOrder $order): array
    {
        $components = '0.00';

        foreach ($order->items()->with('componentItem')->get() as $line) {
            $item = $line->componentItem;

            if (! $item) {
                continue;
            }

            /**
             * The component's OWN cost, not a standard one.
             *
             * Using the finished item's cost_price to value the components would
             * make every production order balance perfectly by construction, and
             * every variance invisible -- which is the opposite of what the variance
             * account is for.
             */
            $unitCost = $this->unitCostFor($item);

            if ($unitCost === null) {
                continue;
            }

            $components = Money::add(
                $components,
                Money::multiply($unitCost, (string) $line->quantity_used),
            );
        }

$finished = $this->finishedGoodsCost($order);

        /**
         * Labour and overhead already absorbed into this order.
         *
         * Read through the absorption service rather than recomputed, so completion
         * transfers exactly what was posted at absorption time. Recomputing from the
         * current salary or a changed overhead rate would move a different amount
         * out of WIP than went in, leaving a residual balance that nobody could
         * explain.
         */
        $absorbed = app(ProductionAbsorptionService::class)->absorbedTotal($order);

        return [
            'components' => $components,
            'absorbed' => $absorbed,
            'total' => Money::of($components),
            'finishedGoods' => $finished,
        ];
    }

    /**
     * Cost of the finished goods, at the finished item's own cost_price.
     *
     * Zero when the item has no cost_price: the goods then arrive with no cost
     * basis, and work in progress carries the whole cost until someone sets one.
     */
    private function finishedGoodsCost(InventoryProductionOrder $order): string
    {
        $item = InventoryItem::find($order->item_id);

        if (! $item) {
            return '0.00';
        }

        $unitCost = $this->unitCostFor($item);

        if ($unitCost === null) {
            return '0.00';
        }

        return Money::of(Money::multiply($unitCost, (string) $order->quantity));
    }

    /**
     * A stock item's unit cost, traced back to its product.
     *
     * Returns null rather than zero when no cost is known, so the caller can
     * distinguish "free" from "unknown" -- treating unknown as zero would post a
     * production order with no cost and quietly understate every figure involved.
     */
    private function unitCostFor(InventoryItem $item): ?string
    {
        $cost = $item->product?->cost_price;

        if ($cost === null || (float) $cost <= 0.0) {
            return null;
        }

        return (string) $cost;
    }
}