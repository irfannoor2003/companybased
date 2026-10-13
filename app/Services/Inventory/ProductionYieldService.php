<?php

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use App\Models\InventoryProductionOrder;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * What a production run actually produced, and what that output is worth.
 *
 * A production order recorded only what it was ASKED to produce. On completion the
 * controller wrote `quantity_used = quantity_required` over the component lines with
 * a bulk update, so the shop floor could not report what was really consumed, and
 * finished output was hardcoded to the ordered quantity. Three consequences, all of
 * them accounting rather than cosmetic:
 *
 *   - Components consumed at less than the BOM quantity, and stock written off,
 *     were recorded as consumed in full. The inventory loss disappeared.
 *   - A run that produced 90 good units out of 100 attempted posted 100 into stock.
 *   - Material that went into rejected output had no home. It stayed in the cost of
 *     the good units, quietly flattering the margin on what did sell.
 *
 * The cost question, stated plainly because it is genuinely arguable:
 *
 * Scrap is ABSORBED into the good units rather than expensed separately. The material
 * really was consumed to make them; a run that rejects 10 of 100 produces 90 units
 * that cost what the whole batch cost. Expensing the scrap instead would credit the
 * good units with a cost they were never charged, understating inventory and
 * overstating margin on the units that do sell.
 *
 * What keeps this visible is the existing Production Variance account, which
 * measures the real cost of production against the standard cost price. Scrap
 * absorbed into the goods therefore surfaces there as a variance, rather than
 * disappearing into a blended average nobody can interrogate.
 */
class ProductionYieldService
{
    /**
     * Total cost of the components an order will consume, at each component's own
     * current moving average.
     */
    public function componentCost(InventoryProductionOrder $order): string
    {
        $valuation = app(ValuationService::class);
        $total = '0';

        foreach ($order->items as $line) {
            $component = InventoryItem::find($line->component_item_id);

            if ($component === null) {
                continue;
            }

            $cost = $valuation->averageCost($component);

            if ($cost === null) {
                continue;
            }

            $total = Money::add(
                $total,
                Money::multiply($cost, (string) $line->quantity_used),
            );
        }

        return $total;
    }

    /**
     * Settle what was actually produced.
     *
     * Reported consumption is stored on each component line, then good and scrap
     * output are recorded on the order. A line with no reported figure keeps its
     * required quantity, so an order completed without the sheet floor filling in
     * the paperwork behaves exactly as it did before -- it does not silently
     * consume nothing.
     *
     * @param  array<int, array{component_item_id: int, quantity_used: float|null, quantity_scrapped?: float|null, scrap_reason?: string|null}>  $actuals
     * @param  float|null  $quantityGood  saleable output; null means "not reported"
     *
     * @return array<string, mixed>
     */
    public function settle(
        InventoryProductionOrder $order,
        array $actuals,
        ?float $quantityGood,
        ?float $quantityScrap = 0.0,
        ?string $note = null,
    ): array {
        $scrap = max(0.0, (float) $quantityScrap);

        foreach ($actuals as $actual) {
            $line = $order->items()
                ->where('component_item_id', $actual['component_item_id'])
                ->first();

            if ($line === null) {
                continue;
            }

            $used = $actual['quantity_used'] ?? null;

            $line->forceFill([
                // A line with nothing reported keeps its required quantity. Falling
                // back to zero instead would consume no stock and quietly understate
                // what the job used.
                'quantity_used' => $used === null ? $line->quantity_required : max(0.0, (float) $used),
                'quantity_scrapped' => max(0.0, (float) ($actual['quantity_scrapped'] ?? 0)),
                'scrap_reason' => $actual['scrap_reason'] ?? null,
            ])->save();
        }

        $ordered = (float) $order->quantity;

        // Not reported falls back to the ordered quantity, which is what completion
        // used to assume. Producing less than was ordered is a reportable fact, not
        // something to paper over.
        $good = $quantityGood === null ? $ordered : max(0.0, $quantityGood);

        $order->forceFill([
            'quantity_good' => $good,
            'quantity_scrap' => $scrap,
            'completed_at' => now(),
            'note' => $note ?? $order->note,
        ])->save();

        $cost = $this->componentCost($order->fresh());
        $unitsProduced = $good + $scrap;

        /**
         * Any component line the report did not mention falls back to its required
         * quantity.
         *
         * The first version of this only applied the fallback per line, to lines
         * that appeared in the report -- so a line nobody mentioned kept
         * `quantity_used` at zero, consumed no stock at all, and the order closed
         * with its components still on the shelf. A partial report has to leave the
         * lines it omits exactly as they would have been before.
         */
        $order->items()
            ->where('quantity_used', '<=', 0)
            ->update(['quantity_used' => DB::raw('quantity_required')]);

        // Cost per unit produced, over BOTH good and scrap, because the material
        // went into both. Dividing by the good quantity alone would value the
        // surviving goods at less than they cost and leave the scrap's cost
        // unaccounted for.
        //
        // Rounded to four places: this is a rate, not a ledger amount, and a
        // manufactured unit costing 0.025 loses meaning at the money scale.
        $unitCost = $unitsProduced > 0
            ? Money::round(Money::divide($cost, (string) $unitsProduced), 4)
            : '0.0000';

        return [
            'quantity_good' => $good,
            'quantity_scrap' => $scrap,
            'units_produced' => $unitsProduced,
            'yield_percent' => $unitsProduced > 0 ? round(($good / $unitsProduced) * 100, 2) : 0.0,
            'component_cost' => $cost,
            'unit_cost' => $unitCost,
            'goods_value' => Money::multiply($unitCost, (string) $good),
            'scrap_value' => Money::multiply($unitCost, (string) $scrap),
        ];
    }
}