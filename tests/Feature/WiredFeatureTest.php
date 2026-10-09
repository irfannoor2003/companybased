<?php

namespace Tests\Feature;

use App\Models\Holiday;
use App\Models\PurchaseOrder;
use App\Models\PurchaseQuote;
use App\Models\SalesDeliveryNote;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesQuote;
use App\Models\SalesStatusEvent;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Sixteen routes were fully implemented on the server and completely unreachable
 * from the interface: the delete actions on ten index screens, four status
 * transitions, holiday editing, and the sales-invoice template picker. Nothing in
 * the suite noticed, because the tests exercised the controllers directly and the
 * route:list-versus-views gap is invisible to a per-feature test.
 *
 * These tests go through HTTP and assert the control is present in the rendered
 * markup, because "the route exists" and "a user can reach it" are different
 * claims and only the second one matters.
 */
class WiredFeatureTest extends TestCase
{
    use SeedsDatabase;

    private ?\App\Models\User $admin = null;

    private function admin(): \App\Models\User
    {
        return $this->admin ??= $this->userForRole('Admin');
    }

    private function html(TestResponse $response): string
    {
        $response->assertOk();

        return $response->getContent();
    }

    private function quote(bool $converted = false): SalesQuote
    {
        return SalesQuote::create([
            'number' => 'Q-'.uniqid(),
            'customer_id' => $this->customer()->id,
            'salesman_id' => $this->admin()->id,
            'issue_date' => now()->toDateString(),
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'total' => '100.00',
        ]);
    }

    // ---------------------------------------------------------------- deletes

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function deleteScreens(): array
    {
        return [
            'budgets' => ['/accounting/budgets', 'accounting.budgets.destroy', 'Budget'],
            'expense claims' => ['/accounting/expense-claims', 'accounting.expense_claims.destroy', 'Expense claim'],
            'tax returns' => ['/accounting/tax-returns', 'accounting.tax_returns.destroy', 'Tax return'],
            'reconciliations' => ['/banking/reconciliations', 'banking.reconciliations.destroy', 'Reconciliation'],
            'departments' => ['/employees/departments', 'employees.departments.destroy', 'Department'],
            'employees' => ['/employees/employees', 'employees.employees.destroy', 'Employee'],
            'salary structures' => ['/employees/salary-structures', 'employees.salary_structures.destroy', 'Salary structure'],
            'inventory items' => ['/inventory/items', 'inventory.items.destroy', 'Item'],
        ];
    }

    /**
     * @param  string  $path  the index screen to open
     * @param  string  $routeName  the destroy route that screen must offer
     * @param  string  $label  the record label, for the failure message
     */
    #[DataProvider('deleteScreens')]
    public function test_the_index_screen_offers_a_delete_action(string $path, string $routeName, string $label): void
    {
        // These screens are reached through their own controllers; a representative
        // fixture is created per screen so the row is actually rendered.
        $this->seedScreenRowFor($routeName);

        $html = $this->html($this->actingAs($this->admin())->get($path));

        $this->assertStringContainsString(
            'name="_method" value="DELETE"',
            $html,
            "{$path} has no delete form for {$label}; route {$routeName} is unreachable from the UI.",
        );
    }

    /**
     * The action URLs of every DELETE form in a page.
     *
     * Asserting on the raw URL is unsound here: show, edit, update and destroy all
     * share one URI pattern (/employees/departments/{department}), so
     * route('...destroy', $record) is byte-identical to the record's View link and
     * is present whether or not a delete button exists. The form itself has to be
     * located, not the address.
     *
     * @return array<int, string>
     */
    private function deleteFormTargets(string $html): array
    {
        preg_match_all('/<form\b[^>]*\baction="([^"]+)"[^>]*>(.*?)<\/form>/s', $html, $forms, PREG_SET_ORDER);

        $targets = [];

        foreach ($forms as [, $action, $body]) {
            if (str_contains($body, 'name="_method" value="DELETE"')) {
                $targets[] = html_entity_decode($action, ENT_QUOTES);
            }
        }

        return $targets;
    }

    public function test_bills_withhold_delete_while_payments_exist(): void
    {
        $makeBill = fn (string $paid) => \App\Models\Bill::create([
            'number' => 'BILL-'.uniqid(),
            'vendor_name' => 'Vendor',
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'open',
            'amount' => '500.00',
            'paid_amount' => $paid,
            'currency' => 'USD',
        ]);

        $unpaid = $makeBill('0');
        $paid = $makeBill('100');

        $html = $this->html($this->actingAs($this->admin())->get('/accounting/bills'));

        $targets = $this->deleteFormTargets($html);

        $this->assertContains(
            route('accounting.bills.destroy', $unpaid),
            $targets,
            'an unpaid bill must offer a delete form',
        );

        $this->assertNotContains(
            route('accounting.bills.destroy', $paid),
            $targets,
            'BillController::destroy() refuses a bill with payments, so the button must be withheld',
        );
    }

    public function test_visited_sites_withhold_delete_while_in_use(): void
    {
        $empty = \App\Models\Department::create([
            'name' => 'Empty Dept '.uniqid(),
            'code' => strtoupper(substr(md5(uniqid()), 0, 6)),
            'is_active' => true,
        ]);

        $populated = \App\Models\Department::create([
            'name' => 'Staffed Dept '.uniqid(),
            'code' => strtoupper(substr(md5(uniqid()), 0, 6)),
            'is_active' => true,
        ]);

        \App\Models\Employee::create([
            'first_name' => 'Test',
            'last_name' => 'Person',
            'employee_code' => 'EMP-'.uniqid(),
            'date_hired' => now()->subYear()->toDateString(),
            'employment_status' => 'active',
            'department_id' => $populated->id,
        ]);

        $html = $this->html($this->actingAs($this->admin())->get('/employees/departments'));

        $targets = $this->deleteFormTargets($html);

        $this->assertContains(route('employees.departments.destroy', $empty), $targets);
        $this->assertNotContains(
            route('employees.departments.destroy', $populated),
            $targets,
            'DepartmentController::destroy() refuses a department with employees',
        );
    }

    public function test_a_completed_reconciliation_withholds_delete(): void
    {
        $bankAccount = \App\Models\BankAccount::create([
            'name' => 'Test Bank '.uniqid(),
            'account_number' => (string) random_int(100000, 999999),
            'bank_name' => 'Test Bank',
            'account_type' => 'current',
            'currency' => 'USD',
            'opening_balance' => '1000.00',
            'is_active' => true,
        ]);

        $makeReconciliation = fn (string $status) => \App\Models\Reconciliation::create([
            'number' => 'REC-'.uniqid(),
            'bank_account_id' => $bankAccount->id,
            'statement_date' => now()->subDay()->toDateString(),
            'statement_ending_balance' => '100.00',
            'status' => $status,
        ]);

        $open = $makeReconciliation('in_progress');
        $completed = $makeReconciliation('completed');

        $html = $this->html($this->actingAs($this->admin())->get('/banking/reconciliations'));

        $targets = $this->deleteFormTargets($html);

        $this->assertContains(route('banking.reconciliations.destroy', $open), $targets);
        $this->assertNotContains(
            route('banking.reconciliations.destroy', $completed),
            $targets,
            'ReconciliationController::destroy() refuses a completed reconciliation',
        );
    }

    // ---------------------------------------------------------------- status

    public function test_the_quote_edit_screen_can_change_status(): void
    {
        $quote = $this->quote();

        $html = $this->html($this->actingAs($this->admin())->get("/sales/quotes/{$quote->id}/edit"));

        $this->assertStringContainsString(
            route('sales.quotes.status', $quote),
            $html,
            'the quote edit screen must offer a status form; route sales.quotes.status was unreachable',
        );
        $this->assertStringContainsString('name="status"', $html);
    }

    public function test_a_converted_quote_hides_the_status_form(): void
    {
        $quote = $this->quote();
        $quote->update(['status' => 'converted']);

        $html = $this->html($this->actingAs($this->admin())->get("/sales/quotes/{$quote->id}/edit"));

        $this->assertStringNotContainsString(
            route('sales.quotes.status', $quote),
            $html,
            'QuoteController::updateStatus() refuses a converted quote, so the form must be withheld',
        );
    }

    public function test_the_purchase_quote_edit_screen_can_change_status(): void
    {
        $quote = PurchaseQuote::create([
            'number' => 'PQ-'.uniqid(),
            'supplier_id' => $this->supplier()->id,
            'issue_date' => now()->toDateString(),
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'total' => '100.00',
        ]);

        $html = $this->html($this->actingAs($this->admin())->get("/suppliers/purchase-quotes/{$quote->id}/edit"));

        $this->assertStringContainsString(route('suppliers.purchase_quotes.status', $quote), $html);
    }

    public function test_the_tracking_screen_can_record_an_order_transition(): void
    {
        [$order] = $this->productWithStockItem();
        $customer = $this->customer();

        $salesOrder = SalesOrder::create([
            'number' => 'SO-'.uniqid(),
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'status' => 'confirmed',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '0',
            'tax_amount' => '0',
            'total' => '0',
        ]);

        SalesOrderItem::create([
            'order_id' => $salesOrder->id,
            'product_id' => $order->id,
            'description' => 'Widget',
            'qty' => '1',
            'unit_price' => '100.00',
            'line_total' => '100.00',
        ]);

        SalesStatusEvent::create([
            'trackable_type' => SalesOrder::class,
            'trackable_id' => $salesOrder->id,
            'from_status' => 'draft',
            'to_status' => 'confirmed',
            'user_id' => $this->admin()->id,
            'note' => 'Seeded for the tracking screen.',
        ]);

        $html = $this->html($this->actingAs($this->admin())->get('/sales/tracking'));

        $this->assertStringContainsString(
            route('sales.tracking.orders.status', $salesOrder),
            $html,
            'the tracking screen must offer the order status form; route sales.tracking.orders.status was unreachable',
        );
        $this->assertStringContainsString('name="note"', $html, 'the transition note field must be present');
    }

    public function test_the_tracking_screen_can_record_a_delivery_transition(): void
    {
        $delivery = SalesDeliveryNote::create([
            'number' => 'DN-'.uniqid(),
            'customer_id' => $this->customer()->id,
            'issue_date' => now()->toDateString(),
            'status' => 'pending',
            'shipping_address' => 'Somewhere',
        ]);

        SalesStatusEvent::create([
            'trackable_type' => SalesDeliveryNote::class,
            'trackable_id' => $delivery->id,
            'from_status' => null,
            'to_status' => 'pending',
            'user_id' => $this->admin()->id,
            'note' => 'Seeded for the tracking screen.',
        ]);

        $html = $this->html($this->actingAs($this->admin())->get('/sales/tracking?type=deliveries'));

        $this->assertStringContainsString(route('sales.tracking.deliveries.status', $delivery), $html);
    }

    /**
     * The control is per document, not per event: an order with several
     * transitions must not render several identical controls for the same record.
     */
    public function test_the_status_control_is_rendered_once_per_document(): void
    {
        $salesOrder = SalesOrder::create([
            'number' => 'SO-'.uniqid(),
            'customer_id' => $this->customer()->id,
            'issue_date' => now()->toDateString(),
            'status' => 'shipped',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '0',
            'tax_amount' => '0',
            'total' => '0',
        ]);

        foreach (['confirmed', 'shipped', 'delivered'] as $index => $status) {
            SalesStatusEvent::create([
                'trackable_type' => SalesOrder::class,
                'trackable_id' => $salesOrder->id,
                'from_status' => $index === 0 ? 'draft' : $salesOrder->status,
                'to_status' => $status,
                'user_id' => $this->admin()->id,
                'note' => null,
            ]);
        }

        $html = $this->html($this->actingAs($this->admin())->get('/sales/tracking?type=orders'));

        $this->assertSame(
            1,
            substr_count($html, route('sales.tracking.orders.status', $salesOrder)),
            'exactly one status control per document; the timeline lists one row per event',
        );
    }

    // ---------------------------------------------------------------- holidays

    public function test_a_holiday_can_be_edited_from_the_calendar(): void
    {
        $holiday = Holiday::create([
            'holiday_date' => now()->addDays(10)->toDateString(),
            'name' => 'Founder Day',
            'description' => 'Original wording',
            'is_default' => false,
            'is_active' => true,
        ]);

        $html = $this->html($this->actingAs($this->admin())->get('/employees/holidays?year='.now()->year));

        $this->assertStringContainsString(
            route('employees.holidays.update', $holiday),
            $html,
            'the holiday calendar must offer an edit form; route employees.holidays.update was unreachable',
        );
        $this->assertStringContainsString('Founder Day', $html);
    }

    public function test_editing_a_holiday_persists(): void
    {
        $holiday = Holiday::create([
            'holiday_date' => now()->addDays(11)->toDateString(),
            'name' => 'Typo Name',
            'description' => null,
            'is_default' => false,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->put("/employees/holidays/{$holiday->id}", [
                'holiday_date' => now()->addDays(11)->toDateString(),
                'name' => 'Corrected Name',
                'description' => 'Fixed',
            ])
            ->assertRedirect();

        $holiday->refresh();

        $this->assertSame('Corrected Name', $holiday->name);
        $this->assertSame('Fixed', $holiday->description);
        $this->assertFalse((bool) $holiday->is_active, 'an unchecked box must deactivate the holiday');
    }

    // ---------------------------------------------------------------- template

    public function test_the_invoice_screen_offers_a_template_picker(): void
    {
        $invoice = \App\Models\SalesInvoice::create([
            'number' => 'INV-'.uniqid(),
            'customer_id' => $this->customer()->id,
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'total' => '100.00',
        ]);

        $html = $this->html($this->actingAs($this->admin())->get("/sales/invoices/{$invoice->id}"));

        $this->assertStringContainsString(
            route('sales.invoices.template', $invoice),
            $html,
            'the invoice screen must offer the template picker; route sales.invoices.template was unreachable',
        );
        $this->assertStringContainsString('name="template"', $html);
    }

    public function test_the_template_picker_persists(): void
    {
        $invoice = \App\Models\SalesInvoice::create([
            'number' => 'INV-'.uniqid(),
            'customer_id' => $this->customer()->id,
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '0',
            'tax_amount' => '0',
            'total' => '0',
        ]);

        $this->actingAs($this->admin())
            ->put("/sales/invoices/{$invoice->id}/template", ['template' => 'corporate'])
            ->assertRedirect(route('sales.invoices.show', $invoice));

        $this->assertSame('corporate', $invoice->fresh()->template);
    }

    /**
     * Create one row so the screen's table is not empty. Screens differ in their
     * required columns, so each is handled explicitly rather than generically.
     */
    private function seedScreenRowFor(string $routeName): void
    {
        $account = $this->account('1000', 'asset', 'Cash');

        match ($routeName) {
            'accounting.budgets.destroy' => \App\Models\Budget::create([
                'name' => 'Budget '.uniqid(),
                'fiscal_year' => (int) now()->year,
                'status' => 'draft',
                'currency' => 'USD',
                'is_active' => true,
            ]),
            'accounting.expense_claims.destroy' => \App\Models\ExpenseClaim::create([
                'number' => 'EC-'.uniqid(),
                'employee_name' => 'Someone',
                'expense_type' => 'travel',
                'expense_date' => now()->subDay()->toDateString(),
                'amount' => '50.00',
                'currency' => 'USD',
                'status' => 'submitted',
            ]),
            'accounting.tax_returns.destroy' => \App\Models\TaxReturn::create([
                'number' => 'TR-'.uniqid(),
                'tax_type' => 'sales',
                'period_label' => 'FY'.now()->year,
                'period_start' => now()->startOfYear()->toDateString(),
                'period_end' => now()->endOfYear()->toDateString(),
                'taxable_amount' => '1000.00',
                'tax_due' => '100.00',
                'currency' => 'USD',
                'status' => 'draft',
            ]),
            'banking.reconciliations.destroy' => \App\Models\Reconciliation::create([
                'number' => 'REC-'.uniqid(),
                'bank_account_id' => \App\Models\BankAccount::create([
                    'name' => 'Seed Bank '.uniqid(),
                    'account_number' => (string) random_int(100000, 999999),
                    'bank_name' => 'Seed Bank',
                    'account_type' => 'current',
                    'currency' => 'USD',
                    'opening_balance' => '0.00',
                    'is_active' => true,
                ])->id,
                'statement_date' => now()->subDay()->toDateString(),
                'statement_ending_balance' => '100.00',
                'status' => 'in_progress',
            ]),
            'employees.departments.destroy' => \App\Models\Department::create([
                'name' => 'Dept '.uniqid(),
                'code' => strtoupper(substr(md5(uniqid()), 0, 6)),
                'is_active' => true,
            ]),
            'employees.salary_structures.destroy' => \App\Models\SalaryStructure::create([
                'employee_id' => $this->employeeUserForRole('Employee')->employee?->id,
                'effective_from' => now()->subMonth()->toDateString(),
                'basic_salary' => '50000.00',
                'is_active' => true,
            ]),
            'inventory.items.destroy' => (function () {
                [$product] = $this->productWithStockItem();

                return \App\Models\InventoryItem::create([
                    'product_id' => $product->id,
                    'reorder_level' => '0',
                    'reorder_quantity' => '0',
                    'is_active' => true,
                ]);
            })(),
            default => null,
        };
    }
}
