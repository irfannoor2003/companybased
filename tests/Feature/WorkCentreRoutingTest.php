<?php

namespace Tests\Feature;

use App\Models\InventoryBillOfMaterial;
use App\Models\InventoryBomOperation;
use App\Models\InventoryItem;
use App\Models\InventoryProductionOrder;
use App\Models\InventoryProductionOrderOperation;
use App\Models\InventoryWorkCentre;
use App\Models\Product;
use App\Services\Inventory\RoutingService;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Work centres, and the routings that say which centre makes what.
 *
 * There was no work centre, machine or equipment record anywhere in this application.
 * ProductionAbsorptionService absorbed overhead on direct labour hours and said why:
 * "there is no work centre, machine or equipment record anywhere in this
 * application, so a machine-hour rate would have nothing to measure against". That
 * was an honest limitation rather than a bug, and it is now removed.
 *
 * The load-bearing property is that recorded time survives. A standard time nothing
 * records against is an assumption, so releasing a routing must never overwrite work
 * the shop floor has already reported.
 */
class WorkCentreRoutingTest extends TestCase
{
    use SeedsDatabase;

    private ?\App\Models\User $admin = null;

    private function admin(): \App\Models\User
    {
        return $this->admin ??= $this->userForRole('Admin');
    }

    private function centre(array $attributes = []): InventoryWorkCentre
    {
        static $n = 0;

        return InventoryWorkCentre::create(array_merge([
            'code' => 'WC-'.(++$n),
            'name' => 'Work centre '.$n,
            'type' => 'machine',
            'hourly_rate' => '60.0000',
            'capacity_minutes_per_day' => 480,
            'is_active' => true,
        ], $attributes));
    }

    private function item(string $name): InventoryItem
    {
        $product = Product::create([
            'sku' => 'SKU-'.strtoupper(substr(md5($name.microtime()), 0, 8)),
            'name' => $name,
            'price' => 100.00,
            'cost_price' => '10.00',
            'is_active' => true,
            'track_inventory' => true,
        ]);

        return InventoryItem::create(['product_id' => $product->id, 'is_active' => true]);
    }

    private function order(InventoryBillOfMaterial $bom, float $quantity = 100.0): InventoryProductionOrder
    {
        return InventoryProductionOrder::create([
            'number' => 'MO-'.uniqid(),
            'item_id' => $bom->item_id,
            'bill_of_material_id' => $bom->id,
            'warehouse_id' => $this->warehouse()->id,
            'quantity' => $quantity,
            'status' => 'draft',
            'scheduled_end_date' => today()->toDateString(),
        ]);
    }

    /**
     * A routing: cut for 10 minutes, then assemble at 2 minutes a unit.
     */
    private function routedBom(InventoryWorkCentre $cutting, InventoryWorkCentre $assembly): InventoryBillOfMaterial
    {
        $product = $this->item('Widget');

        $bom = InventoryBillOfMaterial::create([
            'name' => 'BOM for Widget',
            'item_id' => $product->id,
            'version' => '1',
            'status' => 'active',
        ]);

        $bom->operations()->create([
            'work_centre_id' => $cutting->id,
            'sequence' => 1,
            'name' => 'Cut',
            'setup_minutes' => 10,
            'run_minutes_per_unit' => 0,
        ]);

        $bom->operations()->create([
            'work_centre_id' => $assembly->id,
            'sequence' => 2,
            'name' => 'Assemble',
            'setup_minutes' => 5,
            'run_minutes_per_unit' => 2,
        ]);

        return $bom->fresh();
    }

    /**
     * Standard time = setup + (run x quantity). A 10-unit and a 1,000-unit batch of
     * the same product take very different times on the same centre, and a routing
     * that could not express that would be no use for planning.
     */
    public function test_standard_time_combines_setup_with_run_per_unit(): void
    {
        $cutting = $this->centre();
        $assembly = $this->centre();
        $bom = $this->routedBom($cutting, $assembly);

        $cut = $bom->operations()->where('sequence', 1)->first();
        $assemble = $bom->operations()->where('sequence', 2)->first();

        // Setup only, because run time is zero.
        $this->assertEqualsWithDelta(10.0, $cut->standardMinutes(1000), 0.01);

        // 5 + (2 x 10)
        $this->assertEqualsWithDelta(25.0, $assemble->standardMinutes(10), 0.01);

        // 5 + (2 x 1000)
        $this->assertEqualsWithDelta(2005.0, $assemble->standardMinutes(1000), 0.01);
    }

    /**
     * Releasing copies the routing onto the order, with planned times for THIS batch.
     */
    public function test_releasing_a_routing_copies_it_onto_the_order(): void
    {
        $cutting = $this->centre();
        $assembly = $this->centre();
        $bom = $this->routedBom($cutting, $assembly);
        $order = $this->order($bom, 10.0);

        $result = app(RoutingService::class)->release($order, $bom);

        $this->assertSame(2, $result['released']);

        $operations = $order->operations()->with('workCentre')->orderBy('sequence')->get();

        $this->assertCount(2, $operations);
        $this->assertSame('Cut', $operations[0]->name);
        $this->assertSame('Cutting', $operations[0]->workCentre->name === $cutting->name ? 'Cutting' : $operations[0]->workCentre->name);
        $this->assertEqualsWithDelta(10.0, (float) $operations[0]->planned_minutes, 0.01);
        $this->assertEqualsWithDelta(25.0, (float) $operations[1]->planned_minutes, 0.01);
    }

    /**
     * The load-bearing property: recorded time survives a re-release.
     *
     * Editing a BOM must not retime work already half done, and the only way to
     * guarantee that is for the order to own its own copy of the routing.
     */
    public function test_releasing_again_never_overwrites_recorded_time(): void
    {
        $cutting = $this->centre();
        $assembly = $this->centre();
        $bom = $this->routedBom($cutting, $assembly);
        $order = $this->order($bom, 10.0);

        app(RoutingService::class)->release($order, $bom);

        $assemble = $order->operations()->where('sequence', 2)->first();
        app(RoutingService::class)->recordMinutes($assemble, 30.0);

        // Somebody edits the recipe: the standard time doubles.
        $bom->operations()->where('sequence', 2)->update(['run_minutes_per_unit' => 4]);

        app(RoutingService::class)->release($order, $bom->fresh());

        $assemble->refresh();

        $this->assertEqualsWithDelta(
            30.0,
            (float) $assemble->minutes_spent,
            0.01,
            'recorded time must never be overwritten',
        );
        $this->assertEqualsWithDelta(
            25.0,
            (float) $assemble->planned_minutes,
            0.01,
            'the planned side of a reported operation is left alone too',
        );
    }

    /**
     * An UNREPORTED operation may be re-planned, which is how a supervisor corrects
     * the sequence of a job nobody has started on.
     */
    public function test_an_unreported_operation_can_be_replanned(): void
    {
        $cutting = $this->centre();
        $assembly = $this->centre();
        $bom = $this->routedBom($cutting, $assembly);
        $order = $this->order($bom, 10.0);

        app(RoutingService::class)->release($order, $bom);

        $bom->operations()->where('sequence', 2)->update(['setup_minutes' => 15]);

        app(RoutingService::class)->release($order, $bom->fresh());

        $this->assertEqualsWithDelta(
            35.0, // 15 + (2 x 10)
            (float) $order->operations()->where('sequence', 2)->first()->planned_minutes,
            0.01,
            'an operation nobody has reported on must be re-plannable',
        );
    }

    /**
     * Reported minutes ACCUMULATE.
     *
     * A supervisor who clocks an operation in and then tops it up because the job
     * ran long expects a total. Overwriting would discard the first entry, which is
     * the one most likely to exist on paper.
     */
    public function test_reported_minutes_accumulate(): void
    {
        $bom = $this->routedBom($this->centre(), $this->centre());
        $order = $this->order($bom, 10.0);

        app(RoutingService::class)->release($order, $bom);
        $operation = $order->operations()->where('sequence', 1)->first();

        app(RoutingService::class)->recordMinutes($operation, 12.0);
        app(RoutingService::class)->recordMinutes($operation, 5.0);

        $this->assertEqualsWithDelta(17.0, (float) $operation->refresh()->minutes_spent, 0.01);
        $this->assertSame('complete', $operation->status);
    }

    /**
     * An operation nobody has reported on has NO variance, which is different from
     * zero. Reporting zero would claim it was infinitely quick.
     */
    public function test_an_unreported_operation_has_no_variance(): void
    {
        $bom = $this->routedBom($this->centre(), $this->centre());
        $order = $this->order($bom, 10.0);

        app(RoutingService::class)->release($order, $bom);
        $operation = $order->operations()->where('sequence', 1)->first();

        $this->assertNull($operation->varianceMinutes());

        app(RoutingService::class)->recordMinutes($operation, 8.0);

        $this->assertEqualsWithDelta(2.0, $operation->refresh()->varianceMinutes(), 0.01, '10 planned, 8 actual');
    }

    /**
     * A retired work centre cannot take new work, so its operation is not released.
     * Skipping it silently would leave the order claiming a route that cannot run.
     */
    public function test_a_retired_work_center_is_not_released(): void
    {
        $cutting = $this->centre();
        $assembly = $this->centre(['is_active' => false]);

        $bom = $this->routedBom($cutting, $assembly);
        $order = $this->order($bom, 10.0);

        $result = app(RoutingService::class)->release($order, $bom);

        $this->assertSame(1, $result['released']);
        $this->assertSame(1, $result['skipped'], 'the retired centre must be reported as skipped');
        $this->assertCount(1, $order->operations()->get());
    }

    /**
     * Overloading a centre is visible before the job is late.
     */
    public function test_capacity_reports_an_overloaded_centre(): void
    {
        // 15 minutes a day, so the second order genuinely overloads it.
        $cutting = $this->centre(['capacity_minutes_per_day' => 15]);
        $assembly = $this->centre(['capacity_minutes_per_day' => 480]);

        $bom = $this->routedBom($cutting, $assembly);

        // 10 units: cutting is 10 minutes (setup only) against 15. Under.
        $small = $this->order($bom, 10.0);
        app(RoutingService::class)->release($small, $bom);

        $rows = collect(app(RoutingService::class)->capacityFor(today()->toDateString()));
        $cuttingRow = $rows->first(fn ($r) => $r['work_centre']->id === $cutting->id);

        $this->assertEqualsWithDelta(10.0, $cuttingRow['committed'], 0.01);
        $this->assertEqualsWithDelta(5.0, $cuttingRow['remaining'], 0.01);
        $this->assertFalse($cuttingRow['over_capacity']);

        // A second job: 20 minutes against 15. Over.
        $big = $this->order($bom, 10.0);
        app(RoutingService::class)->release($big, $bom);

        $rows = collect(app(RoutingService::class)->capacityFor(today()->toDateString()));
        $cuttingRow = $rows->first(fn ($r) => $r['work_centre']->id === $cutting->id);

        $this->assertEqualsWithDelta(20.0, $cuttingRow['committed'], 0.01, '10 minutes for each of two jobs');
        $this->assertTrue($cuttingRow['over_capacity'], '20 minutes against a 15 minute day is over capacity');
        $this->assertEqualsWithDelta(0.0, $cuttingRow['remaining'], 0.01, 'remaining never goes negative');
    }

    /**
     * Recorded minutes count even when they exceed the plan, and even once the
     * operation is complete: a centre that is taking longer than it should is
     * exactly the one worth surfacing.
     */
    public function test_capacity_counts_recorded_time_over_planned(): void
    {
        $cutting = $this->centre(['capacity_minutes_per_day' => 60]);
        $bom = $this->routedBom($cutting, $this->centre());
        $order = $this->order($bom, 10.0);

        app(RoutingService::class)->release($order, $bom);
        $operation = $order->operations()->where('sequence', 1)->first();
        app(RoutingService::class)->recordMinutes($operation, 200.0);

        $this->assertSame('complete', $operation->refresh()->status);

        $rows = collect(app(RoutingService::class)->capacityFor(today()->toDateString()));
        $row = $rows->first(fn ($r) => $r['work_centre']->id === $cutting->id);

        $this->assertEqualsWithDelta(200.0, $row['committed'], 0.01, 'recorded time beats the plan');
        $this->assertTrue($row['over_capacity']);
    }

    /**
     * A centre with no stated capacity is unconstrained, not infinitely large.
     */
    public function test_a_centre_with_no_capacity_is_unconstrained(): void
    {
        $centre = $this->centre(['capacity_minutes_per_day' => null]);

        $load = $centre->capacityFor(today()->toDateString());

        $this->assertNull($load['capacity']);
        $this->assertNull($load['remaining']);
        $this->assertFalse($load['over_capacity']);
    }

    public function test_a_work_centre_can_be_created_and_edited(): void
    {
        $this->actingAs($this->admin())
            ->post('/inventory/work-centres', [
                'code' => 'PRESS-01',
                'name' => 'Hydraulic Press',
                'type' => 'machine',
                'hourly_rate' => '85.5',
                'capacity_minutes_per_day' => 420,
            ])
            ->assertSessionHasNoErrors();

        $centre = InventoryWorkCentre::where('code', 'PRESS-01')->firstOrFail();

        $this->actingAs($this->admin())
            ->put('/inventory/work-centres/'.$centre->id, [
                'code' => 'PRESS-01',
                'name' => 'Hydraulic Press 1',
                'type' => 'machine',
                'hourly_rate' => '90',
                'capacity_minutes_per_day' => 400,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Hydraulic Press 1', $centre->refresh()->name);
    }

    /**
     * A centre with recorded operations must not be deleted out from under them.
     */
    public function test_a_used_work_centre_cannot_be_deleted(): void
    {
        $cutting = $this->centre();
        $bom = $this->routedBom($cutting, $this->centre());
        $order = $this->order($bom, 10.0);
        app(RoutingService::class)->release($order, $bom);

        $this->actingAs($this->admin())
            ->delete('/inventory/work-centres/'.$cutting->id)
            ->assertSessionHas('toasts');

        $this->assertNotNull($cutting->fresh());
    }

    public function test_the_capacity_page_renders(): void
    {
        $this->centre();

        $this->actingAs($this->admin())
            ->get('/inventory/work-centres/capacity')
            ->assertOk()
            ->assertSee('Work centre capacity');
    }

    public function test_the_capacity_route_is_not_swallowed_by_the_parameter_route(): void
    {
        $this->actingAs($this->admin())
            ->get('/inventory/work-centres/capacity')
            ->assertOk();

        $this->assertNotNull(
            request()?->route()?->getName(),
            'the capacity route must resolve to its own controller action',
        );
    }
}