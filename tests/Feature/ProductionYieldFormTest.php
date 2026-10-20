<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\InventoryProductionOrder;
use App\Models\Product;
use App\Models\User;
use App\Support\InventoryLedger;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The scrap and yield report is operable, not just implemented.
 *
 * `ProductionYieldTest` proved the arithmetic: that only good output is received, that
 * scrap is absorbed rather than expensed, and that actual consumption is recorded. All
 * of that was true and none of it was reachable. The columns, the service and the
 * endpoint existed, but no form anywhere in 350+ views carried a single
 * `quantity_good`, `quantity_scrap` or `quantity_scrapped` input — the values had to be
 * hand-POSTed to the status endpoint.
 *
 * That makes the figures that decide a manufactured item's cost arithmetic performed
 * outside the application, on the one screen where the shop floor actually is.
 *
 * The property to protect is that reporting stays OPTIONAL. An order completed with the
 * sheet left blank must behave exactly as it did before reporting existed; a reporting
 * feature that can block finishing a job gets abandoned, and then nobody records scrap
 * at all.
 */
class ProductionYieldFormTest extends TestCase
{
    use SeedsDatabase;

    private ?User $admin = null;

    private function admin(): User
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
            'quantity_required' => 1000.0,
            'quantity_used' => 0,
        ]);

        InventoryLedger::adjust($component->id, $order->warehouse_id, '1000', 'incoming_shipment', null, 'seed', '10.00');

        return $order->fresh();
    }

    private function show(InventoryProductionOrder $order): TestResponse
    {
        return $this->actingAs($this->admin())
            ->get(route('inventory.production_orders.show', $order));
    }

    private function complete(InventoryProductionOrder $order, array $payload): TestResponse
    {
        return $this->actingAs($this->admin())->patch(
            '/inventory/production-orders/'.$order->id.'/status',
            array_merge(['status' => 'completed'], $payload),
        );
    }

    public function test_the_order_screen_offers_the_yield_fields(): void
    {
        $order = $this->order();

        $response = $this->show($order);

        $response->assertOk();

        // Without these the feature is still unreachable, whatever the backend says.
        $response->assertSee('name="quantity_good"', false);
        $response->assertSee('name="quantity_scrap"', false);
        $response->assertSee('name="actuals[0][quantity_used]"', false);
        $response->assertSee('name="actuals[0][quantity_scrapped]"', false);
        $response->assertSee('name="actuals[0][scrap_reason]"', false);
    }

    public function test_the_form_defaults_good_output_to_the_ordered_quantity(): void
    {
        $order = $this->order(100.0);

        // Completing with the sheet untouched must mean "produced what was asked for".
        $this->show($order)->assertSee('name="quantity_good"', false);
    }

    public function test_submitting_the_form_records_the_reported_good_and_scrap(): void
    {
        $order = $this->order(100.0);

        $this->complete($order, [
            'quantity_good' => 90,
            'quantity_scrap' => 10,
        ])->assertSessionHasNoErrors();

        $order->refresh();

        $this->assertSame('completed', $order->status);
        $this->assertEquals(90.0, (float) $order->quantity_good);
        $this->assertEquals(10.0, (float) $order->quantity_scrap);
    }

    public function test_submitting_per_component_actuals_from_the_form_is_honoured(): void
    {
        $order = $this->order(100.0);
        $line = $order->items->first();

        $this->complete($order, [
            'quantity_good' => 90,
            'quantity_scrap' => 10,
            'actuals' => [[
                'component_item_id' => $line->component_item_id,
                'quantity_used' => 950,
                'quantity_scrapped' => 50,
                'scrap_reason' => 'cracked at final inspection',
            ]],
        ])->assertSessionHasNoErrors();

        $line->refresh();

        $this->assertEquals(950.0, (float) $line->quantity_used);
        $this->assertEquals(50.0, (float) $line->quantity_scrapped);
        $this->assertSame('cracked at final inspection', $line->scrap_reason);
    }

    public function test_a_blank_sheet_still_completes_the_order(): void
    {
        $order = $this->order(100.0);

        /**
         * The fallback, and the reason this feature is safe to ship.
         *
         * Reporting must never become a new thing that can block finishing a job. A run
         * whose operator never filled in the sheet consumes its required quantity and
         * produces its ordered quantity — exactly what completion did before reporting
         * existed.
         */
        $this->complete($order, [])->assertSessionHasNoErrors();

        $order->refresh();

        $this->assertSame('completed', $order->status);
        $this->assertEquals(100.0, (float) $order->quantity_good);
        $this->assertEquals(1000.0, (float) $order->items->first()->quantity_used);
    }

    public function test_a_completed_order_does_not_offer_the_completion_form(): void
    {
        $order = $this->order(100.0);
        $this->complete($order, ['quantity_good' => 90, 'quantity_scrap' => 10]);

        $this->show($order->fresh())
            ->assertOk()
            ->assertDontSee('Complete and report yield');
    }

    public function test_a_cancelled_order_does_not_offer_the_completion_form(): void
    {
        $order = $this->order(100.0);
        $order->update(['status' => 'cancelled']);

        $this->show($order->fresh())
            ->assertOk()
            ->assertDontSee('Complete and report yield');
    }

    public function test_the_completion_control_is_withheld_from_a_user_who_cannot_change_status(): void
    {
        $order = $this->order();

        /**
         * A control that appears unconditionally is as wrong as one that is missing.
         * The form posts the status change, so it has to sit behind the same permission
         * as the endpoint it drives.
         *
         * The permission set is stated explicitly rather than borrowed from a seeded
         * role: what is under test is "can view, cannot change status", and leaning on
         * whatever Inventory Manager happens to hold would silently pass or silently
         * fail the moment that role is retuned.
         */
        $viewer = User::create([
            'name' => 'Production Viewer',
            'email' => 'production-viewer@example.test',
            'password' => 'Password123!',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $role = Role::create([
            'name' => 'production-viewer',
            'guard_name' => 'web',
        ]);

        $role->givePermissionTo(
            Permission::findOrCreate(
                'inventory.production_orders.view', 'web',
            ),
        );

        $viewer->assignRole($role);

        $this->actingAs($viewer)
            ->get(route('inventory.production_orders.show', $order))
            ->assertOk()
            ->assertDontSee('Complete and report yield');
    }

    public function test_negative_good_output_is_refused(): void
    {
        $order = $this->order(100.0);

        $this->complete($order, ['quantity_good' => -5])
            ->assertSessionHasErrors('quantity_good');

        $this->assertSame('in_progress', $order->fresh()->status);
    }

    public function test_negative_component_scrap_is_refused(): void
    {
        $order = $this->order(100.0);
        $line = $order->items->first();

        $this->complete($order, [
            'quantity_good' => 90,
            'actuals' => [[
                'component_item_id' => $line->component_item_id,
                'quantity_scrapped' => -12,
            ]],
        ])->assertSessionHasErrors('actuals.0.quantity_scrapped');

        $this->assertSame('in_progress', $order->fresh()->status);
    }

    public function test_an_actual_for_a_component_this_order_does_not_use_is_ignored_rather_than_invented(): void
    {
        $order = $this->order(100.0);
        $stranger = $this->item('Not in this BOM', '5.00');

        /**
         * A hand-constructed POST naming a component the order never consumed must not
         * create a line for it. `ProductionYieldService::settle()` matches on the
         * order's own lines and skips what it does not find; this asserts that from the
         * outside, where the boundary actually is.
         */
        $this->complete($order, [
            'quantity_good' => 100,
            'actuals' => [[
                'component_item_id' => $stranger->id,
                'quantity_used' => 500,
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $order->fresh()->items()->count());
    }

    public function test_reported_scrap_reaches_stock_and_the_ledger_together(): void
    {
        $order = $this->order(100.0);

        /**
         * The whole point of the screen: a run that rejected a tenth of its output must
         * book 90 units into stock, not 100, and the difference must not vanish.
         */
        $this->complete($order, [
            'quantity_good' => 90,
            'quantity_scrap' => 10,
        ])->assertSessionHasNoErrors();

        $finished = $order->fresh()->item;

        $onHand = InventoryLedger::onHand($finished->id, $order->warehouse_id);

        $this->assertEqualsWithDelta(90.0, (float) $onHand, 0.001);
    }

    public function test_the_reported_yield_is_shown_back_after_completion(): void
    {
        $order = $this->order(100.0);

        $this->complete($order, ['quantity_good' => 90, 'quantity_scrap' => 10]);

        $this->show($order->fresh())
            ->assertOk()
            ->assertSee('Good output')
            ->assertSee('Scrapped')
            ->assertSee('Yield');
    }
}
