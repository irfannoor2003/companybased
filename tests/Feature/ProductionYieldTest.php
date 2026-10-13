<?php

namespace Tests\Feature;

use App\Models\InventoryBillOfMaterial;
use App\Models\InventoryItem;
use App\Models\InventoryProductionOrder;
use App\Models\Product;
use App\Services\Inventory\ProductionYieldService;
use App\Support\InventoryLedger;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * A production run reports what it produced, not what it was asked to produce.
 *
 * Completion used to write `quantity_used = quantity_required` over every component
 * line with one bulk update, and receive `order->quantity` into stock. That is not a
 * reporting gap, it is an accounting one, in three places:
 *
 *   - components consumed at less than the BOM quantity, and stock written off
 *     during the run, were recorded as consumed in full, so the loss vanished;
 *   - a run that produced 90 good units out of 100 attempted posted 100 into stock;
 *   - material that went into rejected output had no home at all.
 *
 * The cost decision, which is genuinely arguable and therefore stated in the
 * service: scrap is ABSORBED into the good units. The material really was consumed
 * to make them. Expensing the scrap separately would credit the good units with a
 * cost they were never charged, understating inventory and overstating margin on
 * the units that do sell.
 */
class ProductionYieldTest extends TestCase
{
    use SeedsDatabase;

    private ?\App\Models\User $admin = null;

    private function admin(): \App\Models\User
    {
        return $this->admin ??= $this->userForRole('Admin');
    }

    private function item(string $name, string $cost): InventoryItem
    {
        $product = Product::create([
            'sku' => 'SKU-'.strtoupper(substr(md5($name.microtime()), 0, 8)),
            'name' => $name,
            'price' => 100.00,
            'cost_price' => $cost,
            'is_active' => true,
            'track_inventory' => true,
        ]);

        return InventoryItem::create(['product_id' => $product->id, 'is_active' => true]);
    }

    /**
     * A finished good built from one component at 10 per unit, plus labour-free.
     */
    private function order(float $quantity = 100.0): InventoryProductionOrder
    {
        $component = $this->item('Component', '10.00');
        $finished = $this->item('Finished', '0.00');

        $order = InventoryProductionOrder::create([
            'number' => 'MO-'.uniqid(),
            'item_id' => $finished->id,
            'warehouse_id' => $this->warehouse()->id,
            'quantity' => $quantity,
            'status' => 'in_progress',
        ]);

        $order->items()->create([
            'component_item_id' => $component->id,
            'quantity_required' => 1000.0,   // 100 finished x 10 each
            'quantity_used' => 0,
        ]);

        // Give the component stock and a real average cost.
        InventoryLedger::adjust($component->id, $order->warehouse_id, '1000', 'incoming_shipment', null, 'seed', '10.00');

        return $order->fresh();
    }

    /**
     * Complete an order through the real controller path.
     */
    private function complete(InventoryProductionOrder $order, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin())->patch(
            '/inventory/production-orders/'.$order->id.'/status',
            array_merge(['status' => 'completed'], $payload),
        );
    }

    /**
     * The headline case: 90 good, 10 scrapped, out of 100 ordered.
     */
    public function test_only_good_output_is_received_into_stock(): void
    {
        $order = $this->order(100.0);

        $this->complete($order, [
            'quantity_good' => 90,
            'quantity_scrap' => 10,
            'actuals' => [['component_item_id' => $order->items->first()->component_item_id, 'quantity_used' => 1000]],
        ]);

        $order->refresh();

        $this->assertEqualsWithDelta(90.0, (float) $order->quantity_good, 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $order->quantity_scrap, 0.001);

        // 90 received, NOT the 100 that was ordered.
        $this->assertEqualsWithDelta(
            90.0,
            (float) InventoryLedger::onHand($order->item_id),
            0.001,
            'the scrap must not be received as saleable stock',
        );

        // And the components really were consumed.
        $this->assertEqualsWithDelta(0.0, (float) InventoryLedger::onHand($order->items->first()->component_item_id), 0.001);
    }

    /**
     * Yield is reported so the sheet floor can see the problem.
     */
    public function test_the_yield_is_calculated_and_reported(): void
    {
        $order = $this->order(100.0);

        $response = $this->complete($order, [
            'quantity_good' => 90,
            'quantity_scrap' => 10,
            'actuals' => [['component_item_id' => $order->items->first()->component_item_id, 'quantity_used' => 1000]],
        ]);

        $response->assertSessionHasNoErrors();

        $result = app(ProductionYieldService::class)->settle(
            $order->fresh(),
            [],
            90.0,
            10.0,
        );

        $this->assertEqualsWithDelta(90.0, $result['yield_percent'], 0.01, '90 good of 100 produced');
    }

    /**
     * The cost rule: unit cost is spread over good AND scrap, because the material
     * went into both. Dividing by the good quantity alone would leave the scrap's
     * cost unaccounted for entirely.
     *
     * 1,000 components at 10 = 10,000. Over 100 units produced that is 100 each, so
     * 90 good units are carried at 9,000 -- absorbing the 1,000 the scrap consumed.
     */
    public function test_scrap_cost_is_absorbed_into_the_good_output(): void
    {
        $order = $this->order(100.0);

        $result = app(ProductionYieldService::class)->settle(
            $order,
            [['component_item_id' => $order->items->first()->component_item_id, 'quantity_used' => 1000]],
            90.0,
            10.0,
        );

        $this->assertSame('10000.00', $result['component_cost'], '1,000 components at 10 each');
        $this->assertSame('100.0000', $result['unit_cost'], 'cost per unit over good AND scrap');
        $this->assertSame('9000.00', $result['goods_value'], 'the scrap cost rides on the good units');
        $this->assertSame('1000.00', $result['scrap_value'], 'the scrap consumed a tenth of the batch');
    }

    /**
     * A run that produces less than it was asked for must not be reported as
     * complete output.
     */
    public function test_short_production_is_reported_rather_than_papered_over(): void
    {
        $order = $this->order(100.0);

        $this->complete($order, [
            'quantity_good' => 70,
            'actuals' => [['component_item_id' => $order->items->first()->component_item_id, 'quantity_used' => 700]],
        ]);

        $order->refresh();

        $this->assertEqualsWithDelta(70.0, (float) $order->quantity_good, 0.001);
        $this->assertEqualsWithDelta(
            70.0,
            (float) InventoryLedger::onHand($order->item_id),
            0.001,
            'only what was actually produced may be received',
        );
    }

    /**
     * Backwards compatibility: an order completed without reporting anything behaves
     * exactly as it did before. Reporting is new; it must not become a new thing
     * that can stop a job being closed.
     */
    public function test_an_order_completed_without_a_report_behaves_as_before(): void
    {
        $order = $this->order(100.0);
        $componentId = $order->items->first()->component_item_id;

        $this->complete($order, []);

        $order->refresh();

        $this->assertEqualsWithDelta(100.0, (float) $order->quantity_good, 0.001, 'falls back to the ordered quantity');
        $this->assertEqualsWithDelta(
            100.0,
            (float) InventoryLedger::onHand($order->item_id),
            0.001,
            'the full order is received when nothing is reported',
        );
        $this->assertEqualsWithDelta(
            1000.0,
            (float) $order->items->first()->quantity_used,
            0.001,
            'a component line with nothing reported keeps its required quantity',
        );
    }

    /**
     * Actual consumption under the BOM quantity is the case that used to be
     * invisible: the bulk update recorded the full requirement and the stock
     * written off simply disappeared from the records.
     */
    public function test_actual_consumption_is_recorded_rather_than_inferred(): void
    {
        $order = $this->order(100.0);
        $componentId = $order->items->first()->component_item_id;

        $this->complete($order, [
            'quantity_good' => 100,
            'actuals' => [['component_item_id' => $componentId, 'quantity_used' => 950]],
        ]);

        $this->assertEqualsWithDelta(
            950.0,
            (float) $order->fresh()->items->first()->quantity_used,
            0.001,
            'the reported figure must survive completion',
        );

        // 50 units of component were written off, and must be gone from stock.
        $this->assertEqualsWithDelta(50.0, (float) InventoryLedger::onHand($componentId), 0.001);
    }

    /**
     * Component scrap is recorded with a reason, which is a different problem from
     * output scrap and needs a different fix.
     */
    public function test_component_scrap_is_recorded_with_its_reason(): void
    {
        $order = $this->order(100.0);
        $componentId = $order->items->first()->component_item_id;

        $this->complete($order, [
            'quantity_good' => 100,
            'actuals' => [[
                'component_item_id' => $componentId,
                'quantity_used' => 1000,
                'quantity_scrapped' => 12,
                'scrap_reason' => 'Cracked in press',
            ]],
        ]);

        $line = $order->fresh()->items->first();

        $this->assertEqualsWithDelta(12.0, (float) $line->quantity_scrapped, 0.001);
        $this->assertSame('Cracked in press', $line->scrap_reason);
    }

    /**
     * The finished good must acquire the cost it was actually made at.
     *
     * `applyProduction()` passed no unit cost, so a manufactured product kept
     * whatever average it had before it was ever made -- and goods produced at a
     * component cost of 100 were carried at a standard cost of 0.
     */
    public function test_the_finished_good_is_valued_at_what_it_cost_to_make(): void
    {
        $order = $this->order(100.0);

        $this->complete($order, [
            'quantity_good' => 100,
            'actuals' => [['component_item_id' => $order->items->first()->component_item_id, 'quantity_used' => 1000]],
        ]);

        $this->assertEqualsWithDelta(
            100.0,
            (float) \App\Models\InventoryItem::find($order->item_id)->moving_average_cost,
            0.01,
            'the finished good must carry its real production cost',
        );
    }

    /**
     * A good run reports a full yield and no scrap.
     */
    public function test_a_clean_run_reports_full_yield(): void
    {
        $order = $this->order(50.0);

        $result = app(ProductionYieldService::class)->settle(
            $order,
            [['component_item_id' => $order->items->first()->component_item_id, 'quantity_used' => 500]],
            50.0,
            0.0,
        );

        $this->assertEqualsWithDelta(100.0, $result['yield_percent'], 0.01);
        $this->assertEqualsWithDelta(0.0, $result['scrap_value'], 0.01);
    }
}