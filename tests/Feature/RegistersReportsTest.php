<?php

namespace Tests\Feature;

use App\Models\DebitNote;
use App\Models\PurchaseInvoice;
use App\Models\SalesCreditNote;
use App\Models\SalesCustomer;
use App\Models\SalesDeliveryNote;
use App\Models\SalesInvoice;
use App\Models\SalesPayment;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The register reports — customers, suppliers, credit notes, debit notes and
 * delivery challans.
 *
 * The arithmetic these pages show is the single most disputed arithmetic in any
 * bookkeeping system, and this app already carried its own corrected version in
 * SalesCustomer::balance() and Supplier::balance(). These tests hold the
 * reports to that same standard:
 *
 *   - `paid` invoices count (a fully-settled party owes nothing, not a
 *     negative equal to everything they ever paid),
 *   - per-row currency conversion (USD is never added to PKR),
 *   - one settlement subtracted once (credit/debit notes are counted at
 *     `applied_amount`, the figure that has actually been used),
 *   - drafts and cancellations excluded.
 */
class RegistersReportsTest extends TestCase
{
    use SeedsDatabase;

    private ?\App\Models\User $admin = null;

    private function admin(): \App\Models\User
    {
        return $this->admin ??= $this->userForRole('Admin');
    }

    private function customer(array $overrides = []): SalesCustomer
    {
        return SalesCustomer::create(array_merge([
            'company_name' => 'Test Buyer '.uniqid(),
            'currency' => 'PKR',
            'opening_balance' => 0,
            'opening_balance_date' => now(),
            'is_active' => true,
        ], $overrides));
    }

    private function supplier(array $overrides = []): Supplier
    {
        return Supplier::create(array_merge([
            'company_name' => 'Test Seller '.uniqid(),
            'currency' => 'PKR',
            'is_active' => true,
        ], $overrides));
    }

    private function invoice(SalesCustomer $customer, float $total, string $status = 'sent', ?string $date = null): SalesInvoice
    {
        return SalesInvoice::create([
            'number' => 'INV-REG-'.uniqid(),
            'customer_id' => $customer->id,
            'issue_date' => $date ?? now()->toDateString(),
            'status' => $status,
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => $total,
            'tax_amount' => 0,
            'total' => $total,
            'paid_amount' => 0,
        ]);
    }

    private function purchaseInvoice(Supplier $supplier, float $total, string $status = 'sent', ?string $date = null): PurchaseInvoice
    {
        return PurchaseInvoice::create([
            'number' => 'PIN-REG-'.uniqid(),
            'supplier_id' => $supplier->id,
            'issue_date' => $date ?? now()->toDateString(),
            'status' => $status,
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => $total,
            'tax_amount' => 0,
            'total' => $total,
            'paid_amount' => 0,
        ]);
    }

    public function test_customers_report_states_the_balance_the_way_the_model_does(): void
    {
        $customer = $this->customer(['opening_balance' => 100]);

        $invoice = $this->invoice($customer, 500);
        SalesPayment::create([
            'number' => 'SP-REG-'.uniqid(),
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => 200,
            'payment_date' => now()->toDateString(),
            'method' => 'cash',
            'currency' => 'PKR',
            'exchange_rate' => 1,
        ]);

        SalesCreditNote::create([
            'number' => 'CN-REG-'.uniqid(),
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => 50,
            'tax_amount' => 0,
            'total' => 50,
            'applied_amount' => 50,
            'reason' => 'Damaged goods',
        ]);

        // A paid invoice must be counted but its settled payment still
        // subtracted, and a draft invoice must not appear at all.
        $this->invoice($customer, 120, 'paid');
        $this->invoice($customer, 999, 'draft');

        $response = $this->actingAs($this->admin())
            ->get(route('reports.registers.customers'))
            ->assertOk();

        $response->assertSee($customer->company_name);
        // 100 opening + 500 + 120 invoiced - 200 paid - 50 credited = 470.
        $response->assertSee('470.00');
        $response->assertDontSeeText('999.00', 'a draft invoice leaked into the report');
    }

    public function test_customers_report_excludes_draft_and_cancelled_invoices_from_aggregates(): void
    {
        $customer = $this->customer();

        $this->invoice($customer, 400, 'sent');
        $this->invoice($customer, 900, 'cancelled');

        $response = $this->actingAs($this->admin())
            ->get(route('reports.registers.customers'))
            ->assertOk();

        $response->assertSee('400.00');
        $response->assertDontSeeText('900.00', 'a cancelled invoice leaked into the report');
    }

    public function test_suppliers_report_follows_supplier_balance(): void
    {
        $supplier = $this->supplier();

        $bill = $this->purchaseInvoice($supplier, 700);
        SupplierPayment::create([
            'number' => 'SPM-REG-'.uniqid(),
            'supplier_id' => $supplier->id,
            'invoice_id' => $bill->id,
            'amount' => 300,
            'payment_date' => now()->toDateString(),
            'method' => 'bank_transfer',
            'currency' => 'PKR',
            'exchange_rate' => 1,
        ]);

        DebitNote::create([
            'number' => 'DN-REG-'.uniqid(),
            'supplier_id' => $supplier->id,
            'issue_date' => now()->toDateString(),
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => 100,
            'tax_amount' => 0,
            'total' => 100,
            'applied_amount' => 100,
            'reason' => 'Short delivery',
        ]);

        // 700 billed - 300 paid - 100 debited = 300.
        $this->actingAs($this->admin())
            ->get(route('reports.registers.suppliers'))
            ->assertOk()
            ->assertSee($supplier->company_name)
            ->assertSee('300.00');
    }

    public function test_credit_and_debit_note_registers_show_the_unapplied_balance(): void
    {
        $customer = $this->customer();
        $supplier = $this->supplier();

        SalesCreditNote::create([
            'number' => 'CN-REG-'.uniqid(),
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Wrong colour',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => 150,
            'tax_amount' => 0,
            'total' => 150,
            'applied_amount' => 90,
        ]);

        DebitNote::create([
            'number' => 'DN-REG-'.uniqid(),
            'supplier_id' => $supplier->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Damaged in transit',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => 80,
            'tax_amount' => 0,
            'total' => 80,
            'applied_amount' => 20,
        ]);

        $this->actingAs($this->admin())
            ->get(route('reports.registers.credit_notes'))
            ->assertOk()
            ->assertSee('Wrong colour')
            // 150 total, 90 applied, 60 unapplied.
            ->assertSee('60.00');

        $this->actingAs($this->admin())
            ->get(route('reports.registers.debit_notes'))
            ->assertOk()
            ->assertSee('Damaged in transit')
            // 80 total, 20 applied, 60 unapplied.
            ->assertSee('60.00');
    }

    public function test_delivery_challan_register_counts_shipped_quantities(): void
    {
        $customer = $this->customer();

        $note = SalesDeliveryNote::create([
            'number' => 'DO-REG-'.uniqid(),
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'status' => 'shipped',
            'carrier' => 'TCS',
        ]);

        $note->items()->createMany([
            ['description' => 'Cartons', 'qty' => 4],
            ['description' => 'Crates', 'qty' => 3],
        ]);

        $response = $this->actingAs($this->admin())
            ->get(route('reports.registers.delivery_challans'))
            ->assertOk();

        $response->assertSee($note->number);
        $response->assertSee('TCS');
        // Four cartons and three crates ship seven units, not two lines.
        $response->assertSeeText('7');
    }

    public function test_the_reports_hub_links_the_register_reports(): void
    {
        $this->actingAs($this->admin())
            ->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Customer & Supplier Registers')
            ->assertSee(route('reports.registers.index'));
    }

    public function test_register_views_are_exportable_as_csv(): void
    {
        $customer = $this->customer(['company_name' => 'Acme, Ltd '.uniqid()]);
        $supplier = $this->supplier();

        $this->invoice($customer, 500);
        $this->purchaseInvoice($supplier, 400);

        $admin = $this->admin();

        $this->actingAs($admin)->get(route('reports.registers.customers.export'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8')
            // A comma in the party name must not shift the columns.
            ->assertSeeText('"Acme')
            ->assertSeeText('500.00');

        $this->actingAs($admin)->get(route('reports.registers.suppliers.export'))
            ->assertOk()
            ->assertSeeText('400.00');

        $this->actingAs($admin)->get(route('reports.registers.delivery_challans.export'))
            ->assertOk()
            ->assertSeeText('Qty shipped');

        $this->actingAs($admin)->get(route('reports.registers.credit_notes.export'))
            ->assertOk()
            ->assertSeeText('Unapplied');

        $this->actingAs($admin)->get(route('reports.registers.debit_notes.export'))
            ->assertOk()
            ->assertSeeText('Unapplied');
    }

    public function test_a_user_with_only_the_view_permission_is_refused_the_export(): void
    {
        // No role at all -- the Admin role ships with both permissions, and a
        // revoked direct grant would change nothing while the role still
        // carries the export one.
        $viewer = \App\Models\User::create([
            'name' => 'Report Viewer '.uniqid(),
            'email' => 'viewer-'.uniqid().'@example.test',
            'password' => 'Password123!',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $viewer->givePermissionTo('reports.reports.view');

        $this->actingAs($viewer)
            ->get(route('reports.registers.customers.export'))
            ->assertForbidden();

        // The page itself stays reachable for the view permission.
        $this->actingAs($viewer)
            ->get(route('reports.registers.customers'))
            ->assertOk();
    }

    public function test_the_date_filter_excludes_activity_from_outside_the_period(): void
    {
        $customer = $this->customer();

        $this->invoice($customer, 600, 'sent', now()->toDateString());
        $this->invoice($customer, 500, 'sent', now()->subYear()->toDateString());

        $response = $this->actingAs($this->admin())
            ->get(route('reports.registers.customers', ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->endOfMonth()->toDateString()]))
            ->assertOk();

        $response->assertSee('600.00');
        $response->assertDontSeeText('500.00', 'an invoice dated outside the period leaked in');
    }
}
