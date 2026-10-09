<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Employee;
use App\Models\InventoryBillOfMaterial;
use App\Models\InventoryBillOfMaterialItem;
use App\Models\InventoryItem;
use App\Models\InventoryProductionOrder;
use App\Models\InventoryProductionOrderItem;
use App\Models\InventoryWarehouse;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductionLabourEntry;
use App\Models\SalaryStructure;
use App\Models\User;
use App\Models\Setting;
use App\Services\Accounting\ProductionAbsorptionService;
use App\Services\Accounting\ProductionPostingService;
use App\Support\InventoryLedger;
use App\Support\Money;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Labour and overhead absorption.
 *
 * Before this, factory labour was expensed in one monthly total. The cost of
 * building goods sat in period expense instead of in the goods, so inventory was
 * understated and every manufactured item's margin was overstated by whatever it
 * cost to make.
 *
 * The decision these tests exist to protect is that absorption is a RECLASS:
 * Dr Work in Progress / Cr Salaries and Wages. Not a second credit to Net Pay
 * Payable, which would leave the business owing staff twice for the same hours.
 */
class ProductionAbsorptionTest extends TestCase
{
    use SeedsDatabase;

    private InventoryWarehouse $warehouse;

    private InventoryItem $finished;

    private InventoryItem $steel;

    /**
 * Created lazily, not in setUp().
 *
 * A setUp() declared here SHADOWS the trait's setUp(): a method on the class always
 * beats one pulled in by a trait. That silently skipped the database seed, so the
 * chart of accounts was empty, LedgerPosting::isReady() returned false, and every
 * posting quietly returned null -- the accounting simply did not happen, and the
 * failures surfaced as unrelated "no results" errors.
 */
private function warehouse(): InventoryWarehouse
{
    return InventoryWarehouse::firstOrCreate(
        ['name' => 'Absorption Warehouse'],
        ['code' => 'ABSW', 'is_active' => true],
    );
}

    private function item(string $name, string $cost): InventoryItem
    {
        $product = Product::create([
            'name' => $name,
            'sku' => 'ABS-'.strtoupper(substr(md5($name), 0, 6)),
            'cost_price' => $cost,
            'retail_price' => '100.00',
            'is_active' => true,
        ]);

        return InventoryItem::firstOrCreate(
            ['product_id' => $product->id],
            ['warehouse_id' => $this->warehouse()->id, 'quantity_on_hand' => 0],
        );
    }

    /**
     * A finished item costing 100, built from one 50-cost component. Finished cost
     * 200 for 10 units vs 500 of components, so the numbers below are readable.
     */
    private function order(float $quantity = 10, float $componentQty = 10): InventoryProductionOrder
    {
        $this->finished ??= $this->item('Absorbed Widget', '100.00');
        $this->steel ??= $this->item('Absorbed Steel', '50.00');

        $bom = InventoryBillOfMaterial::create([
            'name' => 'Absorption BOM',
            'item_id' => $this->finished->id,
            'version' => '1',
            'status' => 'active',
        ]);

        InventoryBillOfMaterialItem::create([
            'bill_of_material_id' => $bom->id,
            'component_item_id' => $this->steel->id,
            'quantity' => '1',
            'wastage_percent' => '0',
        ]);

        $order = InventoryProductionOrder::create([
            'number' => 'MO-'.uniqid(),
            'item_id' => $this->finished->id,
            'bill_of_material_id' => $bom->id,
            'warehouse_id' => $this->warehouse()->id,
            'quantity' => (string) $quantity,
            'status' => 'in_progress',
        ]);

        InventoryProductionOrderItem::create([
            'production_order_id' => $order->id,
            'component_item_id' => $this->steel->id,
            'quantity_required' => (string) $componentQty,
            'quantity_used' => (string) $componentQty,
        ]);

        InventoryLedger::adjust($this->steel->id, $this->warehouse()->id, '100', 'initial');

        return $order->fresh();
    }

    private function employee(float $monthlyGross = 5200.00): Employee
    {
        $employee = Employee::create([
            'employee_code' => 'E-'.uniqid(),
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'date_of_birth' => '1990-01-01',
            'date_hired' => '2020-01-01',
            'employment_status' => 'active',
        ]);

        SalaryStructure::create([
            'employee_id' => $employee->id,
            'effective_from' => now()->subYear()->toDateString(),
            'basic_salary' => $monthlyGross,
            'is_active' => true,
        ]);

        return $employee->fresh();
    }

    private function service(): ProductionAbsorptionService
    {
        return app(ProductionAbsorptionService::class);
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

    public function test_a_monthly_salary_becomes_an_hourly_rate(): void
    {
        // 5200 a month over 208 hours.
        $this->assertSame('25.0000', $this->service()->hourlyRateFor($this->employee()));
    }

    /**
     * A six-day week changes the divisor. Hard-coding 208 would quietly misprice
     * labour for every business that does not run a five-day week.
     */
    public function test_the_monthly_hours_divisor_is_configurable(): void
    {
        Setting::set('production.standard_monthly_hours', 240);

        // 5200 / 240
        $this->assertSame('21.6667', $this->service()->hourlyRateFor($this->employee()));
    }

    public function test_an_employee_with_no_salary_structure_has_no_rate(): void
    {
        $bare = Employee::create([
            'employee_code' => 'E-'.uniqid(),
            'first_name' => 'No',
            'last_name' => 'Pay',
            'date_of_birth' => '1990-01-01',
            'date_hired' => '2024-01-01',
            'employment_status' => 'active',
        ]);

        /**
         * Null, not zero. Zero would absorb this worker's time into production at no
         * cost, understating the goods without anybody noticing.
         */
        $this->assertNull($this->service()->hourlyRateFor($bare));
    }

    /**
     * The core: labour moves from period expense into work in progress.
     */
    public function test_labour_is_reclassified_from_expense_into_work_in_progress(): void
    {
        $order = $this->order();
        $employee = $this->employee();

        $entry = ProductionLabourEntry::create([
            'production_order_id' => $order->id,
            'employee_id' => $employee->id,
            'minutes' => 480,
            'hourly_rate' => '25.0000',
            'labour_cost' => '200.00',
            'work_date' => now()->subDay()->toDateString(),
        ]);

        $posted = $this->service()->absorbLabour($entry);
        $posted->load('items.account');

        $balances = $this->balances($posted);

        // 480 minutes at 25/hr = 200.
        $this->assertSame('200.00', $balances['1350'] ?? '', 'labour did not reach work in progress');
        $this->assertSame('-200.00', $balances['6200'] ?? '', 'labour did not leave period expense');
    }

    /**
     * The trap this design exists to avoid.
     *
     * Payroll already credited 2150 Net Pay Payable for these hours. Crediting it
     * again here would leave the business owing the worker twice and still expensing
     * the cost in full -- counting it once as expense and again as inventory.
     */
    public function test_absorption_does_not_touch_net_pay_payable(): void
    {
        $order = $this->order();
        $employee = $this->employee();

        $entry = ProductionLabourEntry::create([
            'production_order_id' => $order->id,
            'employee_id' => $employee->id,
            'minutes' => 480,
            'hourly_rate' => '25.0000',
            'labour_cost' => '200.00',
            'work_date' => now()->subDay()->toDateString(),
        ]);

        $balances = $this->balances($this->service()->absorbLabour($entry));

        $this->assertArrayNotHasKey(
            '2150',
            $balances,
            'absorbing labour created a second liability to the employee',
        );
    }

    public function test_an_absorbed_entry_is_marked_and_not_reabsorbed(): void
    {
        $order = $this->order();

        $entry = ProductionLabourEntry::create([
            'production_order_id' => $order->id,
            'employee_id' => $this->employee()->id,
            'minutes' => 60,
            'hourly_rate' => '25.0000',
            'labour_cost' => '25.00',
            'work_date' => now()->subDay()->toDateString(),
        ]);

        $this->service()->absorbLabour($entry);
        $this->assertNotNull($entry->fresh()->absorbed_at);

        // A second absorption must be a no-op, not a duplicate journal.
        $this->assertNull($this->service()->absorbLabour($entry->fresh()));
    }

    public function test_zero_cost_labour_posts_nothing(): void
    {
        $order = $this->order();

        $entry = ProductionLabourEntry::create([
            'production_order_id' => $order->id,
            'employee_id' => $this->employee()->id,
            'minutes' => 60,
            'hourly_rate' => '0',
            'labour_cost' => '0',
            'work_date' => now()->subDay()->toDateString(),
        ]);

        $this->assertNull($this->service()->absorbLabour($entry));
    }

    /**
     * Overhead rides on labour hours because there is no machine or work centre
     * anywhere in this application to measure machine hours against.
     */
    public function test_overhead_is_absorbed_on_labour_hours(): void
    {
        Setting::set('production.overhead_rate_per_hour', '10');
        $order = $this->order();

        ProductionLabourEntry::create([
            'production_order_id' => $order->id,
            'employee_id' => $this->employee()->id,
            'minutes' => 300,
            'hourly_rate' => '25.0000',
            'labour_cost' => '125.00',
            'work_date' => now()->subDay()->toDateString(),
        ]);

        $balances = $this->balances($this->service()->absorbOverhead($order->fresh()));

        // 300 minutes = 5 hours at 10/hr.
        $this->assertSame('50.00', $balances['1350'] ?? '');
        $this->assertSame('-50.00', $balances['1450'] ?? '');
    }

    /**
     * Absorbing overhead nobody configured would invent factory cost and put it
     * into inventory.
     */
    public function test_no_overhead_rate_means_no_overhead_is_absorbed(): void
    {
        $order = $this->order();

        ProductionLabourEntry::create([
            'production_order_id' => $order->id,
            'employee_id' => $this->employee()->id,
            'minutes' => 480,
            'hourly_rate' => '25.0000',
            'labour_cost' => '200.00',
            'work_date' => now()->subDay()->toDateString(),
        ]);

        $this->assertNull($this->service()->absorbOverhead($order->fresh()));
    }

    public function test_absorbed_total_combines_labour_and_overhead(): void
    {
        Setting::set('production.overhead_rate_per_hour', '10');
        $order = $this->order();
        $employee = $this->employee();

        $entry = ProductionLabourEntry::create([
            'production_order_id' => $order->id,
            'employee_id' => $employee->id,
            'minutes' => 240,
            'hourly_rate' => '25.0000',
            'labour_cost' => '100.00',
            'work_date' => now()->subDay()->toDateString(),
        ]);

        $this->service()->absorb($order->fresh());

        // 100 labour + 4 hours x 10 overhead.
        $this->assertSame('140.00', $this->service()->absorbedTotal($order->fresh()));
    }

    /**
     * Completion must take the accumulated cost back OUT of WIP.
     *
     * Otherwise labour would be capitalised into goods once and then sit in WIP for
     * ever, and the next order's goods would absorb it a second time.
     */
    public function test_completion_relieves_absorbed_cost_out_of_work_in_progress(): void
    {
        $order = $this->order();

        $entry = ProductionLabourEntry::create([
            'production_order_id' => $order->id,
            'employee_id' => $this->employee()->id,
            'minutes' => 480,
            'hourly_rate' => '25.0000',
            'labour_cost' => '200.00',
            'work_date' => now()->subDay()->toDateString(),
        ]);

        $this->service()->absorb($order->fresh());

        $completion = app(ProductionPostingService::class)->postCompleted($order->fresh());
        $balances = $this->balances($completion);

        $this->assertSame(
            '-200.00',
            $balances['1350'] ?? '',
            'completion did not transfer labour out of work in progress',
        );
    }

    /**
     * Once absorption is the only thing in WIP, completion leaves it at zero. A
     * residual balance would mean cost had been stranded.
     */
    public function test_work_in_progress_returns_to_zero_after_completion(): void
    {
        $order = $this->order();

        ProductionLabourEntry::create([
            'production_order_id' => $order->id,
            'employee_id' => $this->employee()->id,
            'minutes' => 480,
            'hourly_rate' => '25.0000',
            'labour_cost' => '200.00',
            'work_date' => now()->subDay()->toDateString(),
        ]);

        $this->service()->absorb($order->fresh());
        $completion = app(ProductionPostingService::class)->postCompleted($order->fresh());
        $completion->load('items');

        $wip = Account::where('code', '1350')->firstOrFail();

        /**
         * Net across BOTH journals, not just the completion one.
         *
         * Absorption debits WIP and completion credits it. Reading only the
         * completion journal shows a -200 credit with nothing behind it, which looks
         * like cost leaving an empty account. The account is only genuinely empty
         * once the debit that funded it is counted too.
         */
        $balance = (float) \App\Models\JournalEntryItem::where('account_id', $wip->id)
            ->whereIn('journal_entry_id', \App\Models\JournalEntry::query()
                ->where('reference', 'like', '%ProductionLabourEntry:%')
                ->orWhere('reference', 'like', '%InventoryProductionOrder:%')
                ->pluck('id'))
            ->sum(\Illuminate\Support\Facades\DB::raw('debit - credit'));

        $this->assertSame(0.0, $balance, 'work in progress still holds a balance after completion');
    }

    /**
     * The variance compares total cost INCLUDING labour against the goods' carrying
     * value. Excluding labour would book a fictional overrun on every order in a
     * business that absorbs overhead.
     */
    public function test_the_variance_compares_fully_absorbed_cost(): void
    {
        // 1 component at 50 = 50, plus 200 labour = 250 total cost for goods carried
        // at 10 x 100 = 1000. So this underruns and credits the variance.
        $order = $this->order(quantity: 10, componentQty: 1);

        ProductionLabourEntry::create([
            'production_order_id' => $order->id,
            'employee_id' => $this->employee()->id,
            'minutes' => 480,
            'hourly_rate' => '25.0000',
            'labour_cost' => '200.00',
            'work_date' => now()->subDay()->toDateString(),
        ]);

        $this->service()->absorb($order->fresh());
        $balances = $this->balances(app(ProductionPostingService::class)->postCompleted($order->fresh()));

        // 1000 goods - 250 cost = 750 credited back.
        $this->assertSame('-750.00', $balances['6700'] ?? '');
    }

    /**
     * A pay rise must not restate work already done.
     */
    public function test_a_later_pay_rise_does_not_change_recorded_cost(): void
    {
        $employee = $this->employee('5200.00');

        $entry = ProductionLabourEntry::create([
            'production_order_id' => $this->order()->id,
            'employee_id' => $employee->id,
            'minutes' => 480,
            'hourly_rate' => '25.0000',
            'labour_cost' => '200.00',
            'work_date' => now()->subYear()->toDateString(),
        ]);

        SalaryStructure::where('employee_id', $employee->id)->update(['basic_salary' => '99999.00']);

        $this->assertSame('200.00', $entry->fresh()->computedCost());
    }

    public function test_minutes_convert_to_hours_without_rounding_away_work(): void
    {
        $entry = new \App\Models\ProductionLabourEntry(['minutes' => 90, 'hourly_rate' => '25.0000']);

        $this->assertSame(1.5, $entry->hours());
        $this->assertSame('37.50', $entry->computedCost());
    }

    public function test_the_chart_of_accounts_carries_the_absorption_accounts(): void
    {
        foreach (['1350', '1450', '6200'] as $code) {
            $this->assertNotNull(
                Account::where('code', $code)->first(),
                "account {$code} is missing from the chart",
            );
        }
    }

    /**
     * Unabsorbed overhead is a cost, not an asset. Typing 1450 as an asset would
     * park factory overhead on the balance sheet.
     */
    public function test_the_overhead_control_account_is_an_expense(): void
    {
        $this->assertSame('expense', Account::where('code', '1450')->firstOrFail()->type);
    }

    public function test_recording_labour_through_the_order_absorbs_it(): void
    {
        $this->actingAs($this->userForRole('Admin'));

        $order = $this->order();
        $employee = $this->employee();

        $response = $this->post(route('inventory.production_orders.labour.store', $order), [
            'employee_id' => $employee->id,
            'minutes' => 480,
            'work_date' => now()->subDay()->toDateString(),
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $entry = ProductionLabourEntry::where('production_order_id', $order->id)->firstOrFail();

        $this->assertSame('200.00', $entry->labour_cost, '5200/mo over 208h is 25/hr, so 8h is 200');
        $this->assertNotNull($entry->absorbed_at, 'the labour was recorded but never absorbed');
    }

    /**
     * Booking time against a finished order would capitalise labour onto goods that
     * were already valued and posted, quietly restating closed manufacturing.
     */
    public function test_labour_cannot_be_recorded_against_a_completed_order(): void
    {
        $this->actingAs($this->userForRole('Admin'));

        $order = $this->order();
        $order->update(['status' => 'completed']);

        $this->post(route('inventory.production_orders.labour.store', $order), [
            'employee_id' => $this->employee()->id,
            'minutes' => 60,
            'work_date' => now()->subDay()->toDateString(),
        ])->assertRedirect();

        $this->assertSame(0, ProductionLabourEntry::where('production_order_id', $order->id)->count());
    }

    /**
     * A worker with no salary structure has an unknown rate, not a zero one.
     * Accepting it would absorb their time into production at no cost.
     */
    public function test_labour_is_rejected_when_the_employee_has_no_salary(): void
    {
        $this->actingAs($this->userForRole('Admin'));

        $order = $this->order();

        $bare = Employee::create([
            'employee_code' => 'E-'.uniqid(),
            'first_name' => 'No',
            'last_name' => 'Pay',
            'date_of_birth' => '1990-01-01',
            'date_hired' => '2024-01-01',
            'employment_status' => 'active',
        ]);

        $this->post(route('inventory.production_orders.labour.store', $order), [
            'employee_id' => $bare->id,
            'minutes' => 60,
            'work_date' => now()->subDay()->toDateString(),
        ])->assertRedirect();

        $this->assertSame(0, ProductionLabourEntry::where('production_order_id', $order->id)->count());
    }

    public function test_recording_labour_is_closed_without_the_permission(): void
    {
        $order = $this->order();

        $clerk = User::create([
            'name' => 'Clerk',
            'email' => 'clerk-'.uniqid().'@example.test',
            'password' => bcrypt('secret1234'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($clerk);

        $this->post(route('inventory.production_orders.labour.store', $order), [
            'employee_id' => $this->employee()->id,
            'minutes' => 60,
            'work_date' => now()->subDay()->toDateString(),
        ])->assertForbidden();
    }

    public function test_the_order_screen_shows_recorded_labour(): void
    {
        $this->actingAs($this->userForRole('Admin'));

        $order = $this->order();
        $employee = $this->employee();

        ProductionLabourEntry::create([
            'production_order_id' => $order->id,
            'employee_id' => $employee->id,
            'minutes' => 480,
            'hourly_rate' => '25.0000',
            'labour_cost' => '200.00',
            'work_date' => now()->subDay()->toDateString(),
        ]);

        $response = $this->get(route('inventory.production_orders.show', $order));

        $response->assertOk();
        $response->assertSee('Lovelace');
        $response->assertSee('200.00');
    }
}