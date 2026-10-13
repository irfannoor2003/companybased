<?php

namespace App\Services\Inventory;

use App\Models\InventoryBillOfMaterial;
use App\Models\InventoryItem;
use App\Models\InventoryProductionOrder;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Support\InventoryLedger;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Material requirements planning.
 *
 * Net requirements, not a schedule. The question this answers is "what do we not
 * have, and what has to be made or bought to cover demand" -- which is the part
 * that changes decisions. Forward scheduling, capacity loading and lead-time
 * sequencing are a further programme and are not attempted here.
 *
 * The calculation, in order, because the order is the whole point:
 *
 *   gross = demand x BOM quantity
 *   available = on hand + on order - already committed to production
 *   net = max(0, gross - available)
 *
 * On hand comes first because stock already owned needs nothing. Then stock
 * already on order, because buying it twice is the specific waste this avoids.
 * Then quantities already promised to production orders, which is subtracted
 * before anything new is planned -- otherwise work already committed gets done
 * twice and its components are bought twice.
 *
 * Wastage is applied on top of the BOM quantity rather than as a rounding
 * afterthought, because a 5% cutting loss on 1000 metres is 50 metres of shortfall
 * discovered three weeks after the order was placed.
 *
 * Demand is read from CONFIRMED, unfulfilled sales orders only. A draft order is
 * not a commitment and treating it as one would plan material for sales that may
 * never happen; a delivered or cancelled order is already satisfied.
 */
class MaterialRequirementsService
{
    /**
     * The warehouse being planned.
     *
     * Null plans against every warehouse, which is what a single-site business
     * wants: stock anywhere in the business counts toward requirement. Pass a
     * warehouse id to plan one site specifically.
     */
    public function __construct(private readonly ?int $warehouseId = null)
    {
    }

    /**
     * Requirements for one finished item.
     *
     * @return array{
     *     item: InventoryItem,
     *     bom: InventoryBillOfMaterial|null,
     *     quantity: float,
     *     components: array<int, array<string, mixed>>
     * }
     */
    public function requirementsFor(InventoryItem $item, ?Carbon $horizon = null): array
    {
        $bom = $this->bomFor($item);
        $demand = $this->demandFor($item, $horizon);

        return [
            'item' => $item,
            'bom' => $bom,
            'quantity' => $demand,
            'components' => $bom ? $this->explode($bom, $demand) : [],
        ];
    }

    /**
     * Net requirements for every item that has demand or a bill of materials.
     *
     * @return array<int, array<string, mixed>>
     */
    public function plan(?Carbon $horizon = null): array
    {
        $planned = [];

        // Only items that are actually produced need exploding. An item with
        // demand but no BOM is bought outright, which the caller handles.
        $items = InventoryItem::query()
            ->whereHas('product')
            ->with('product')
            ->get();

        foreach ($items as $item) {
            $demand = $this->demandFor($item, $horizon);

            if ($demand <= 0) {
                continue;
            }

            $plan = $this->requirementsFor($item, $horizon);

            if ($plan['components'] === []) {
                continue;
            }

            $planned[$item->id] = $plan;
        }

        return $planned;
    }

/**
     * Component requirements for a BOM at a quantity, net of what is available.
     *
     * Multi-level. A component that is itself built from a recipe is expanded into
     * the parts below it rather than reported as itself -- an earlier version read
     * only the top level, so planning told the buyer to purchase an unbuilt
     * sub-assembly that no supplier sells.
     *
     * @return array<int, array<string, mixed>>
     */
    public function explode(InventoryBillOfMaterial $bom, float $quantity): array
    {
        $explosion = app(BomExplosionService::class);
        $leaves = $explosion->explode($bom, $quantity);

        $lines = [];

        foreach ($leaves as $leaf) {
            $component = $leaf['item'];

            /**
             * Stock comes from the stock table, not from a column on the item.
             *
             * Reading it through InventoryLedger::onHand() is what makes this agree
             * with the rest of the application: inventory_items has no
             * quantity_on_hand, so a figure read from one would be 0 forever and
             * every component would look permanently short.
             */
            $onHand = (float) InventoryLedger::onHand($component->id, $this->warehouseId);
            $onOrder = $this->onOrder($component);
            $committed = $this->committedToProduction($component);

            $available = $onHand + $onOrder - $committed;
            $net = max(0.0, $leaf['gross'] - $available);

            $lines[] = [
                'item' => $component,
                'product' => $component->product,
                'bom_item' => $leaf['bom_item'],
                'level' => $leaf['level'],
                'path' => $leaf['path'],
                'gross' => round($leaf['gross'], 3),
                'on_hand' => round($onHand, 3),
                'on_order' => round($onOrder, 3),
                'committed' => round($committed, 3),
                'available' => round($available, 3),
                'net' => round($net, 3),
                'is_short' => $net > 0.0005,
                'below_reorder' => $net <= 0.0005
                    && (float) $component->reorder_level > 0
                    && $onHand < (float) $component->reorder_level,
                'reorder_level' => (float) $component->reorder_level,
                'reorder_quantity' => (float) $component->reorder_quantity,
            ];
        }

        usort($lines, fn ($a, $b) => [$b['is_short'], $b['net']] <=> [$a['is_short'], $a['net']]);

        return $lines;
    }

    /**
     * The active BOM for an item.
     *
     * Prefers an active BOM, newest version first. A production order must be
     * costed against a specific recipe, and picking an arbitrary one would make
     * the costing unreproducible.
     */
    public function bomFor(InventoryItem $item): ?InventoryBillOfMaterial
    {
        return InventoryBillOfMaterial::query()
            ->where('item_id', $item->id)
            ->where('status', 'active')
            ->with('items')
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Outstanding demand for an item from confirmed, unfulfilled sales orders.
     */
    public function demandFor(InventoryItem $item, ?Carbon $horizon = null): float
    {
        $productId = $item->product_id;

        if (! $productId) {
            return 0.0;
        }

        $query = SalesOrderItem::query()
            ->where('product_id', $productId)
            ->whereHas('order', function ($q) use ($horizon) {
                $q->whereIn('status', ['confirmed', 'packed'])
                ->when($horizon, fn ($q) => $q->where(function ($q) use ($horizon) {
                    // An order with no expected date is still demand.
                    $q->whereNull('expected_delivery_date')
                        ->orWhereDate('expected_delivery_date', '<=', $horizon->toDateString());
                }));
            });

        return round((float) $query->sum('qty'), 3);
    }

    /**
     * Quantity already on a purchase order but not yet received.
     */
    public function onOrder(InventoryItem $component): float
    {
        return round((float) \App\Models\PurchaseOrderItem::query()
            ->where('product_id', $component->product_id)
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['draft', 'sent', 'confirmed', 'partial_received']))
            ->selectRaw('COALESCE(SUM(qty - received_qty), 0) AS outstanding')
            ->value('outstanding'), 3);
    }

    /**
     * Quantity promised to production orders not yet completed.
     *
     * Deducted from availability, because material already scheduled to be
     * consumed is not free -- planning it again would buy the same steel twice.
     */
    public function committedToProduction(InventoryItem $component): float
    {
        return round((float) \App\Models\InventoryProductionOrderItem::query()
            ->where('component_item_id', $component->id)
            ->whereHas('productionOrder', fn ($q) => $q->whereIn('status', ['draft', 'in_progress']))
            ->sum('quantity_required'), 3);
    }

    /**
     * A plain-language summary, because a planning screen nobody can act on is
     * a report rather than a plan.
     */
    public static function summarise(array $plan): array
    {
        $components = [];
        $short = 0;
        $reorder = 0;
        $value = 0.0;

        foreach ($plan as $entry) {
            foreach ($entry['components'] as $line) {
                $components[] = $line;

                if ($line['is_short']) {
                    $short++;
                    $value += (float) ($line['product']?->cost_price ?? 0) * $line['net'];
                } elseif ($line['below_reorder']) {
                    $reorder++;
                }
            }
        }

        return [
            'items' => count($plan),
            'components' => count($components),
            'shortages' => $short,
            'to_reorder' => $reorder,
            'purchase_value' => Money::of($value),
        ];
    }
}