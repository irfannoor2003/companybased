<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\InventoryBillOfMaterial;
use App\Models\InventoryItem;
use App\Models\InventoryProductionOrder;
use App\Models\InventoryWorkCentre;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductionLabourEntry;
use App\Models\SalaryStructure;
use App\Models\Setting;
use App\Services\Accounting\ProductionAbsorptionService;
use App\Support\Money;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Overhead absorption can be charged on a work centre hour, not only a labour hour.
 *
 * Absorption was labour-hour only, and its docblock said why: "there is no work centre,
 * machine or equipment record anywhere in this application, so a machine-hour rate
 * would have nothing to measure against."
 *
 * That reason expired when work centres arrived -- but the BASIS was deliberately not
 * switched underneath existing users, because the two price the same job differently
 * and silently repricing live inventory is a change nobody should discover in a margin
 * report. So both exist, `production.absorption_basis` chooses, and labour hours remain
 * the default.
 *
 * The property that matters most here is that the two bases CANNOT drift: the figure
 * posted to the ledger and the figure completion relieves work in progress by are the
 * same number, or WIP never returns to zero and the books stop reconciling.
 */
class AbsorptionBasisTest extends TestCase
{
    use SeedsDatabase;

    private function service(): ProductionAbsorptionService
    {
        return app(ProductionAbsorptionService::class);
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

    private function order(): InventoryProductionOrder
    {
        $finishedProduct = Product::create([
            'name' => 'Basis Widget', 'sku' => 'BAS-'.uniqid(),
            'cost_price' => '0.00', 'retail_price' => '100.00', 'is_active' => true,
        ]);
        $componentProduct = Product::create([
            'name' => 'Basis Steel', 'sku' => 'BAS-'.uniqid(),
            'cost_price' => '10.00', 'retail_price' => '20.00', 'is_active' => true,
        ]);

        $finished = InventoryItem::create(['product_id' => $finishedProduct->id, 'is_active' => true]);
        $component = InventoryItem::create(['product_id' => $componentProduct->id, 'is_active' => true]);

        $bom = InventoryBillOfMaterial::create([
            'name' => 'Basis BOM', 'item_id' => $finished->id,
            'version' => '1', 'status' => 'active',
        ]);

        $bom->items()->create([
            'component_item_id' => $component->id, 'quantity' => 2, 'wastage_percent' => 0,
        ]);

        $order = InventoryProductionOrder::create([
            'number' => 'MO-'.uniqid(),
            'item_id' => $finished->id,
            'bill_of_material_id' => $bom->id,
            'warehouse_id' => $this->warehouse()->id,
            'quantity' => 10,
            'status' => 'in_progress',
        ]);

        $order->items()->create([
            'component_item_id' => $component->id, 'quantity_required' => 20, 'quantity_used' => 20,
        ]);

        return $order->fresh();
    }

    /**
     * @return array<string, string> net balances by account code
     */
    private function balances(JournalEntry $entry): array
    {
        $entry->load('items.account');

        $out = [];

        foreach ($entry->items as $item) {
            $code = $item->account->code;
            $out[$code] = Money::add($out[$code] ?? '0.00', Money::subtract($item->debit, $item->credit));
        }

        return $out;
    }

    public function test_labour_hour_is_the_default_basis(): void
    {
        /**
         * The default has to be the behaviour every existing installation already has.
         * Switching a setting nobody has touched must not restate their inventory.
         */
        $this->assertSame(
            ProductionAbsorptionService::BASIS_LABOUR_HOUR,
            $this->service()->basis(),
        );
    }

    public function test_an_unrecognised_basis_falls_back_to_labour_hour(): void
    {
        /**
         * A typo in a setting must not stop a production order completing, and the
         * fallback has to be the previous behaviour rather than zero.
         */
        Setting::set('production.absorption_basis', 'machine_hours_by_vibes');

        $this->assertSame(ProductionAbsorptionService::BASIS_LABOUR_HOUR, $this->service()->basis());
    }

    public function test_work_centre_hour_uses_each_centres_own_rate(): void
    {
        Setting::set('production.absorption_basis', ProductionAbsorptionService::BASIS_WORK_CENTRE_HOUR);

        $order = $this->order();

        // 100 minutes at 60/hr = 100.00
        $expensive = $this->centre(['hourly_rate' => '60.0000']);
        // 100 minutes at 30/hr = 50.00
        $cheap = $this->centre(['hourly_rate' => '30.0000']);

        $order->operations()->create([
            'sequence' => 1, 'name' => 'Cut', 'work_centre_id' => $expensive->id, 'planned_minutes' => 100,
        ]);
        $order->operations()->create([
            'sequence' => 2, 'name' => 'Trim', 'work_centre_id' => $cheap->id, 'planned_minutes' => 100,
        ]);

        $this->assertSame('150.00', $this->service()->overheadFor($order->fresh()));
    }

    public function test_work_centre_hour_ignores_the_blended_labour_rate_entirely(): void
    {
        Setting::set('production.absorption_basis', ProductionAbsorptionService::BASIS_WORK_CENTRE_HOUR);

        // A blended rate left over from the labour-hour basis.
        Setting::set('production.overhead_rate_per_hour', '999');

        $order = $this->order();
        $centre = $this->centre(['hourly_rate' => '60.0000']);

        $order->operations()->create([
            'sequence' => 1, 'name' => 'Cut', 'work_centre_id' => $centre->id, 'planned_minutes' => 60,
        ]);

        // 60 minutes at the centre's own 60/hr = 60.00, not 999/hr.
        $this->assertSame('60.00', $this->service()->overheadFor($order->fresh()));
    }

    public function test_recorded_time_wins_over_the_standard_time(): void
    {
        Setting::set('production.absorption_basis', ProductionAbsorptionService::BASIS_WORK_CENTRE_HOUR);

        $order = $this->order();
        $centre = $this->centre(['hourly_rate' => '60.0000']);

        $order->operations()->create([
            'sequence' => 1, 'name' => 'Cut', 'work_centre_id' => $centre->id,
            'planned_minutes' => 60,
            'minutes_spent' => 90,
            'status' => 'complete',
        ]);

        /**
         * A job that overran its standard consumed 90 minutes from the centre, not 60.
         * Billing the standard would understate the overhead the job actually caused.
         */
        $this->assertSame('90.00', $this->service()->overheadFor($order->fresh()));
    }

    public function test_an_operation_quicker_than_standard_is_charged_on_the_actual_time(): void
    {
        Setting::set('production.absorption_basis', ProductionAbsorptionService::BASIS_WORK_CENTRE_HOUR);

        $order = $this->order();
        $centre = $this->centre(['hourly_rate' => '60.0000']);

        $order->operations()->create([
            'sequence' => 1, 'name' => 'Cut', 'work_centre_id' => $centre->id,
            'planned_minutes' => 120,
            'minutes_spent' => 30,
            'status' => 'complete',
        ]);

        $this->assertSame('30.00', $this->service()->overheadFor($order->fresh()));
    }

    public function test_an_unreported_operation_is_absorbed_on_its_standard_time(): void
    {
        Setting::set('production.absorption_basis', ProductionAbsorptionService::BASIS_WORK_CENTRE_HOUR);

        $order = $this->order();
        $centre = $this->centre(['hourly_rate' => '60.0000']);

        $order->operations()->create([
            'sequence' => 1, 'name' => 'Cut', 'work_centre_id' => $centre->id, 'planned_minutes' => 100,
        ]);

        /**
         * Nobody has clocked this one yet, and "unknown" is not "zero" -- reporting zero
         * would claim the job was infinitely quick. The standard is the only figure
         * available, so it is used until a real one arrives.
         */
        $this->assertSame('100.00', $this->service()->overheadFor($order->fresh()));
    }

    public function test_a_work_centre_with_no_rate_contributes_nothing(): void
    {
        Setting::set('production.absorption_basis', ProductionAbsorptionService::BASIS_WORK_CENTRE_HOUR);

        $order = $this->order();

        // Never costed, so there is no honest rate to bill it at.
        $unrated = $this->centre(['hourly_rate' => '0.0000']);

        $order->operations()->create([
            'sequence' => 1, 'name' => 'Bench work', 'work_centre_id' => $unrated->id, 'planned_minutes' => 200,
        ]);

        /**
         * Falling back to the global blended rate here would invent cost the business
         * never configured and put it straight into inventory.
         */
        $this->assertSame('0.00', $this->service()->overheadFor($order->fresh()));
    }

    public function test_labour_hour_basis_ignores_work_centres_entirely(): void
    {
        Setting::set('production.absorption_basis', ProductionAbsorptionService::BASIS_LABOUR_HOUR);
        Setting::set('production.overhead_rate_per_hour', '10');

        $order = $this->order();
        $centre = $this->centre(['hourly_rate' => '999.0000']);

        $order->operations()->create([
            'sequence' => 1, 'name' => 'Cut', 'work_centre_id' => $centre->id, 'planned_minutes' => 600,
        ]);

        // No labour recorded, so the labour-hour basis has nothing to charge.
        $this->assertSame('0.00', $this->service()->overheadFor($order->fresh()));
    }

    public function test_the_posted_journal_balances_on_the_work_centre_basis(): void
    {
        Setting::set('production.absorption_basis', ProductionAbsorptionService::BASIS_WORK_CENTRE_HOUR);

        $order = $this->order();
        $centre = $this->centre(['hourly_rate' => '60.0000']);

        $order->operations()->create([
            'sequence' => 1, 'name' => 'Cut', 'work_centre_id' => $centre->id, 'planned_minutes' => 120,
        ]);

        $balances = $this->balances($this->service()->absorbOverhead($order->fresh()));

        $this->assertSame('120.00', $balances['1350'] ?? '');
        $this->assertSame('-120.00', $balances['1450'] ?? '');
    }

    public function test_absorbed_total_and_the_posted_figure_are_the_same_number(): void
    {
        /**
         * The one property that keeps work in progress reconciling.
         *
         * `absorbOverhead()` writes the figure to the ledger; `absorbedTotal()` is what
         * completion relieves work in progress by. If those two ever disagree, WIP never
         * returns to zero after an order closes and the books stop tying up.
         */
        Setting::set('production.absorption_basis', ProductionAbsorptionService::BASIS_WORK_CENTRE_HOUR);

        $order = $this->order();

        $centreA = $this->centre(['hourly_rate' => '45.5000']);
        $centreB = $this->centre(['hourly_rate' => '17.2500']);

        $order->operations()->create([
            'sequence' => 1, 'name' => 'Cut', 'work_centre_id' => $centreA->id,
            'planned_minutes' => 77, 'minutes_spent' => 90, 'status' => 'complete',
        ]);
        $order->operations()->create([
            'sequence' => 2, 'name' => 'Finish', 'work_centre_id' => $centreB->id,
            'planned_minutes' => 45, 'minutes_spent' => 40, 'status' => 'complete',
        ]);

        $this->service()->absorbOverhead($order->fresh());

        $this->assertSame(
            $this->service()->overheadFor($order->fresh()),
            $this->service()->absorbedTotal($order->fresh()),
        );
    }

    public function test_work_centre_overhead_is_not_double_posted_on_a_re_run(): void
    {
        Setting::set('production.absorption_basis', ProductionAbsorptionService::BASIS_WORK_CENTRE_HOUR);

        $order = $this->order();
        $centre = $this->centre(['hourly_rate' => '60.0000']);

        $order->operations()->create([
            'sequence' => 1, 'name' => 'Cut', 'work_centre_id' => $centre->id, 'planned_minutes' => 120,
        ]);

        $first = $this->service()->absorbOverhead($order->fresh());
        $second = $this->service()->absorbOverhead($order->fresh());

        $this->assertNotNull($first);
        $this->assertNull($second, 'overhead was posted twice for the same order');
        $this->assertSame(1, JournalEntry::where('description', 'like', '%Overhead absorbed%')->count());
    }

    public function test_the_settings_screen_reports_the_current_basis(): void
    {
        Setting::set('production.absorption_basis', ProductionAbsorptionService::BASIS_WORK_CENTRE_HOUR);

        $this->actingAs($this->userForRole('Admin'))
            ->get(route('settings.manufacturing'))
            ->assertOk()
            ->assertSee('Work centre hour')
            ->assertSee('Direct labour hour');
    }

    public function test_an_admin_can_switch_the_basis_from_the_interface(): void
    {
        $this->centre(['hourly_rate' => '75.0000']);

        $this->actingAs($this->userForRole('Admin'))
            ->put(route('settings.manufacturing.update'), [
                'absorption_basis' => ProductionAbsorptionService::BASIS_WORK_CENTRE_HOUR,
                'standard_monthly_hours' => 208,
                'overhead_rate_per_hour' => 0,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ProductionAbsorptionService::BASIS_WORK_CENTRE_HOUR,
            $this->service()->basis(),
        );
    }

    public function test_the_work_centre_basis_cannot_be_selected_with_nothing_to_price_it(): void
    {
        /**
         * Selecting it with no rated centre would absorb nothing while the screen
         * reported the setting as `work_centre_hour` -- a configuration that reads as
         * correct and books nothing. Refusing it makes the mismatch impossible to create.
         */
        $response = $this->actingAs($this->userForRole('Admin'))
            ->put(route('settings.manufacturing.update'), [
                'absorption_basis' => ProductionAbsorptionService::BASIS_WORK_CENTRE_HOUR,
                'standard_monthly_hours' => 208,
                'overhead_rate_per_hour' => 0,
            ]);

        $response->assertSessionHas('toasts');

        $this->assertSame(
            ProductionAbsorptionService::BASIS_LABOUR_HOUR,
            $this->service()->basis(),
            'the basis changed despite every work centre being unrated',
        );
    }

    public function test_the_settings_screen_warns_when_no_work_centre_is_rated(): void
    {
        $this->centre(['hourly_rate' => '0.0000']);

        $this->actingAs($this->userForRole('Admin'))
            ->get(route('settings.manufacturing'))
            ->assertOk()
            ->assertSee('hourly rate');
    }

    public function test_an_unknown_basis_cannot_be_posted_to_the_settings_screen(): void
    {
        $this->actingAs($this->userForRole('Admin'))
            ->put(route('settings.manufacturing.update'), [
                'absorption_basis' => 'by-vibes',
                'standard_monthly_hours' => 208,
                'overhead_rate_per_hour' => 0,
            ])
            ->assertSessionHasErrors('absorption_basis');
    }

    public function test_a_viewer_cannot_change_the_manufacturing_settings(): void
    {
        $superAdmin = $this->userForRole('Super Admin');

        $this->actingAs($superAdmin)
            ->get(route('settings.manufacturing'))
            ->assertForbidden();

        $this->actingAs($superAdmin)
            ->put(route('settings.manufacturing.update'), [
                'absorption_basis' => ProductionAbsorptionService::BASIS_WORK_CENTRE_HOUR,
                'standard_monthly_hours' => 208,
                'overhead_rate_per_hour' => 0,
            ])
            ->assertForbidden();
    }

    public function test_the_seeded_default_leaves_overhead_unaltered_for_a_fresh_install(): void
    {
        /**
         * A fresh install must absorb exactly what it absorbed before any of this: no
         * overhead at all, because the seeded rate is zero.
         */
        $this->assertSame(
            '0.00',
            $this->service()->overheadFor($this->order()),
        );
    }

    public function test_labour_is_still_absorbed_the_same_way_on_either_basis(): void
    {
        /**
         * The basis switch is about OVERHEAD only. Labour capitalisation is not part of
         * it, and must not move.
         */
        Setting::set('production.absorption_basis', ProductionAbsorptionService::BASIS_WORK_CENTRE_HOUR);

        $order = $this->order();
        $employee = $this->employeeWithSalary();

        $entry = ProductionLabourEntry::create([
            'production_order_id' => $order->id,
            'employee_id' => $employee->id,
            'minutes' => 120,
            'hourly_rate' => '25.0000',
            'labour_cost' => '50.00',
            'work_date' => now()->subDay()->toDateString(),
        ]);

        $balances = $this->balances($this->service()->absorbLabour($entry));

        $this->assertSame('50.00', $balances['1350'] ?? '');
        $this->assertSame('-50.00', $balances['6200'] ?? '');
    }

    private function employeeWithSalary(): Employee
    {
        $employee = Employee::create([
            'employee_code' => 'E-'.uniqid(),
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'date_of_birth' => '1990-01-01',
            'date_hired' => '2020-01-01',
            'employment_status' => 'active',
        ]);

        SalaryStructure::create([
            'employee_id' => $employee->id,
            'effective_from' => now()->subYear()->toDateString(),
            'basic_salary' => 5200.00,
            'is_active' => true,
        ]);

        return $employee;
    }
}
