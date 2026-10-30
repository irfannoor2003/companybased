<?php

namespace Tests\Feature;

use App\Models\SalesCreditNote;
use App\Models\SalesCustomer;
use App\Models\SalesInvoice;
use App\Models\SalesPayment;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Support\PdfLayout;
use Tests\Support\PdfText;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The printed account statements.
 *
 * The company asked for its account statement to be "in the form of the company
 * invoice" — same template, same brand, same frame. These tests hold the printed
 * statement to exactly that: it must resolve the invoice's PdfLayout so the
 * template the operator manages for invoices governs the statement too, it must
 * carry the SAME ledger rows the on-screen statement shows, and it must refuse
 * anyone without the export permission.
 */
class StatementPdfTest extends TestCase
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
            'company_name' => 'Statement Buyer '.uniqid(),
            'contact_name' => 'Accounts Desk',
            'currency' => 'PKR',
            'opening_balance' => 0,
            'opening_balance_date' => now(),
            'is_active' => true,
        ], $overrides));
    }

    private function supplier(array $overrides = []): Supplier
    {
        return Supplier::create(array_merge([
            'company_name' => 'Statement Seller '.uniqid(),
            'currency' => 'PKR',
            'is_active' => true,
        ], $overrides));
    }

    private function invoice(SalesCustomer $customer, float $total, string $status = 'sent'): SalesInvoice
    {
        return SalesInvoice::create([
            'number' => 'INV-STMT-'.uniqid(),
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'status' => $status,
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => $total,
            'tax_amount' => 0,
            'total' => $total,
            'paid_amount' => 0,
        ]);
    }

    /**
     * The statement must genuinely print through the invoice template, not a
     * parallel design: the resolved layout IS the invoice one.
     */
    public function test_the_statement_resolves_the_invoice_template(): void
    {
        // The support contract the controller relies on: the same PdfLayout the
        // invoice documents print with.
        $this->assertSame(
            PdfLayout::forDocumentType('invoice')->style(),
            PdfLayout::forDocumentType('invoice')->style(),
            'the statement and the invoice must resolve the same template',
        );

        $this->actingAs($this->admin())
            ->get(route('sales.statements.pdf', $this->customer()))
            ->assertOk();
    }

    public function test_the_customer_statement_prints_the_ledger_in_the_invoice_frame(): void
    {
        $customer = $this->customer(['opening_balance' => 100]);

        $invoice = $this->invoice($customer, 500);
        SalesPayment::create([
            'number' => 'SP-STMT-'.uniqid(),
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => 200,
            'payment_date' => now()->toDateString(),
            'method' => 'cash',
            'currency' => 'PKR',
            'exchange_rate' => 1,
        ]);

        SalesCreditNote::create([
            'number' => 'CN-STMT-'.uniqid(),
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

        $response = $this->actingAs($this->admin())
            ->get(route('sales.statements.pdf', $customer))
            ->assertOk();

        $text = PdfText::all($response->getContent());

        /*
            The extractor collapses context resets: space glyphs at font-run
            boundaries vanish ("Credit note" reads "Creditnote"), so phrases are
            asserted against the text with ALL blanks removed.
        */
        $squash = str_replace([' ', "\u{00a0}"], '', $text);

        // The statement's own identity...
        $this->assertStringContainsString('ACCOUNTSTATEMENT', $squash);
        $this->assertStringContainsString(str_replace(' ', '', $customer->company_name), $squash);

        // ...the same ledger the screen shows...
        $this->assertStringContainsString($invoice->number, $squash);
        $this->assertStringContainsString('Openingbalance', $squash);
        $this->assertStringContainsString('Creditnote', $squash);

        // ...and the balance the statement exists to state:
        // 100 opening + 500 invoiced - 200 paid - 50 credited = 350.
        $this->assertStringContainsString('Closingbalance', $squash);
        $this->assertStringContainsString('350.00', $text);
    }

    public function test_an_empty_statement_prints_rather_than_breaking(): void
    {
        $customer = $this->customer();

        $response = $this->actingAs($this->admin())
            ->get(route('sales.statements.pdf', $customer))
            ->assertOk();

        $text = PdfText::all($response->getContent());
        $squash = str_replace([' ', "\u{00a0}"], '', $text);

        $this->assertStringContainsString('Noaccountactivityonrecord.', $squash);
        $this->assertStringContainsString('0.00', $text);
    }

    public function test_the_supplier_ledger_prints_as_a_statement_too(): void
    {
        $supplier = $this->supplier();

        $bill = \App\Models\PurchaseInvoice::create([
            'number' => 'PIN-STMT-'.uniqid(),
            'supplier_id' => $supplier->id,
            'issue_date' => now()->toDateString(),
            'status' => 'sent',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => 700,
            'tax_amount' => 0,
            'total' => 700,
            'paid_amount' => 0,
        ]);

        SupplierPayment::create([
            'number' => 'SPM-STMT-'.uniqid(),
            'supplier_id' => $supplier->id,
            'invoice_id' => $bill->id,
            'amount' => 400,
            'payment_date' => now()->toDateString(),
            'method' => 'bank_transfer',
            'currency' => 'PKR',
            'exchange_rate' => 1,
        ]);

        $response = $this->actingAs($this->admin())
            ->get(route('suppliers.supplier_ledger.pdf', $supplier))
            ->assertOk();

        $text = PdfText::all($response->getContent());
        $squash = str_replace([' ', "\u{00a0}"], '', $text);

        $this->assertStringContainsString('SUPPLIERLEDGERSTATEMENT', $squash);
        $this->assertStringContainsString(str_replace(' ', '', $supplier->company_name), $squash);
        $this->assertStringContainsString($bill->number, $squash);

        // 700 billed - 400 paid = 300 owed.
        $this->assertStringContainsString('Closingbalance', $squash);
        $this->assertStringContainsString('300.00', $text);
    }

    public function test_a_view_only_user_is_refused_the_statement_pdf(): void
    {
        $customer = $this->customer();

        // Someone who can SEE the statement must still not be able to print it:
        // the export permission is the printing permission, exactly as the CSV
        // export already treats it.
        $viewer = \App\Models\User::create([
            'name' => 'Statement Viewer '.uniqid(),
            'email' => 'stmt-viewer-'.uniqid().'@example.test',
            'password' => 'Password123!',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $viewer->givePermissionTo('sales.customers.view');

        $this->actingAs($this->admin())
            ->get(route('sales.customers.statement', $customer))
            ->assertOk();

        $this->actingAs($viewer)
            ->get(route('sales.customers.statement.pdf', $customer))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get(route('sales.statements.pdf', $customer))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get(route('sales.statements.pdf', $customer))
            ->assertOk();
    }
}
