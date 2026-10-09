<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\SalesPayment;
use App\Models\SupplierPayment;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The cash-flow screen used to total cash, receivables and payables in PHP: it
 * loaded every bank account with every one of its transactions, and every open
 * invoice, then summed the results. On shared hosting that exhausts the memory
 * limit long before the figures are interesting.
 *
 * Those totals are now computed in SQL. Aggregating in a database is only correct
 * if the SQL reproduces the arithmetic of the model method it replaced, so these
 * tests build a known ledger and assert the screen's totals equal the figures
 * derived independently from the models -- the definitions stay the reference.
 *
 * Each fixture is built so its expected total appears nowhere else on the page.
 */
class CashFlowAggregationTest extends TestCase
{
    use SeedsDatabase;

    private ?\App\Models\User $admin = null;

    /**
     * userForRole() inserts a row per call, so the actor is created once.
     */
    private function admin(): \App\Models\User
    {
        return $this->admin ??= $this->userForRole('Admin');
    }

    private function bankAccount(string $name, string $opening): BankAccount
    {
        return BankAccount::create([
            'name' => $name,
            'account_number' => (string) random_int(100000, 999999),
            'bank_name' => 'Test Bank',
            'account_type' => 'current',
            'currency' => 'USD',
            'opening_balance' => $opening,
            'is_active' => true,
        ]);
    }

    private function transaction(BankAccount $account, string $type, string $amount, string $date): void
    {
        BankTransaction::create([
            'bank_account_id' => $account->id,
            'number' => strtoupper($type).'-'.uniqid(),
            'transaction_date' => $date,
            'type' => $type,
            'amount' => $amount,
            'reference' => strtoupper($type).'-'.uniqid(),
            'description' => 'Test '.$type,
        ]);
    }

    private function overview(): string
    {
        return $this->actingAs($this->admin())
            ->get('/cash-flow')
            ->assertOk()
            ->getContent();
    }

    /**
     * Assert a label and its figure appear together.
     *
     * The stat card renders the value above the label, so the two are searched for
     * independently rather than as one adjacent run of markup.
     */
    private function assertFigure(string $html, string $label, string $formatted): void
    {
        $this->assertStringContainsString($label, $html, "the '{$label}' card must be on the page");
        $this->assertStringContainsString(
            $formatted,
            $html,
            "the '{$label}' card must show {$formatted}",
        );
    }

    public function test_cash_and_bank_matches_the_sum_of_account_balances(): void
    {
        $a = $this->bankAccount('Main', '1000.00');
        $b = $this->bankAccount('Savings', '2500.00');

        $this->transaction($a, 'deposit', '400.00', now()->subDays(5)->toDateString());
        $this->transaction($a, 'withdrawal', '150.50', now()->subDays(4)->toDateString());
        $this->transaction($a, 'transfer_in', '25.00', now()->subDays(3)->toDateString());
        $this->transaction($a, 'transfer_out', '75.00', now()->subDays(2)->toDateString());
        $this->transaction($b, 'deposit', '1000.00', now()->subDay()->toDateString());
        $this->transaction($b, 'withdrawal', '200.00', now()->toDateString());

        // Reference: the model method the SQL replaced.
        $expected = round($a->fresh()->balance() + $b->fresh()->balance(), 2);

        $this->assertSame(4499.50, $expected, 'fixture arithmetic: 1199.50 + 3300.00');

        $this->assertFigure($this->overview(), 'Cash &amp; bank balance', '4,499.50');
    }

    public function test_receivables_match_the_sum_of_open_invoice_balances(): void
    {
        $customer = $this->customer();

        $partlyPaid = SalesInvoice::create([
            'number' => 'INV-'.uniqid(),
            'customer_id' => $customer->id,
            'issue_date' => now()->subDays(10)->toDateString(),
            'status' => 'partially_paid',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '300.00',
            'tax_amount' => '0.00',
            'total' => '300.00',
            'paid_amount' => '100.00',
            'credit_applied' => '0',
            'credit_note_applied' => '0',
        ]);

        $sent = SalesInvoice::create([
            'number' => 'INV-'.uniqid(),
            'customer_id' => $customer->id,
            'issue_date' => now()->subDays(5)->toDateString(),
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '200.00',
            'tax_amount' => '0.00',
            'total' => '200.00',
            'paid_amount' => '0',
            'credit_applied' => '0',
            'credit_note_applied' => '0',
        ]);

        // Settled from customer credit: still "overdue" by status, but owes nothing.
        $credited = SalesInvoice::create([
            'number' => 'INV-'.uniqid(),
            'customer_id' => $customer->id,
            'issue_date' => now()->subDays(8)->toDateString(),
            'status' => 'overdue',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '500.00',
            'tax_amount' => '0.00',
            'total' => '500.00',
            'paid_amount' => '0',
            'credit_applied' => '500.00',
            'credit_note_applied' => '0',
        ]);

        // Paid in full: excluded by status.
        SalesInvoice::create([
            'number' => 'INV-'.uniqid(),
            'customer_id' => $customer->id,
            'issue_date' => now()->subDays(9)->toDateString(),
            'status' => 'paid',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '900.00',
            'tax_amount' => '0.00',
            'total' => '900.00',
            'paid_amount' => '900.00',
            'credit_applied' => '0',
            'credit_note_applied' => '0',
        ]);

        $expected = 0.0;
        foreach (SalesInvoice::whereIn('status', ['sent', 'partially_paid', 'overdue'])->get() as $invoice) {
            $expected += to_base_currency($invoice->balance(), $invoice->exchange_rate);
        }

        // 200 (partly paid) + 200 (sent) + 0 (settled by credit).
        $this->assertSame(200.00, $partlyPaid->fresh()->balance());
        $this->assertSame(200.00, $sent->fresh()->balance());
        $this->assertSame(0.00, $credited->fresh()->balance(), 'a credit-settled invoice owes nothing');
        $this->assertSame(400.00, round($expected, 2));

        $this->assertFigure($this->overview(), 'Receivables (owed to us)', '400.00');
    }

    public function test_payables_match_the_sum_of_open_purchase_balances(): void
    {
        $supplier = $this->supplier();

        PurchaseInvoice::create([
            'number' => 'BILL-'.uniqid(),
            'supplier_id' => $supplier->id,
            'issue_date' => now()->subDays(7)->toDateString(),
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '400.00',
            'tax_amount' => '0.00',
            'total' => '400.00',
            'paid_amount' => '150.00',
        ]);

        PurchaseInvoice::create([
            'number' => 'BILL-'.uniqid(),
            'supplier_id' => $supplier->id,
            'issue_date' => now()->subDays(3)->toDateString(),
            'status' => 'partially_paid',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '250.00',
            'tax_amount' => '0.00',
            'total' => '250.00',
            'paid_amount' => '0',
        ]);

        $expected = 0.0;
        foreach (PurchaseInvoice::whereIn('status', ['sent', 'partially_paid', 'overdue'])->get() as $invoice) {
            $expected += to_base_currency($invoice->balance(), $invoice->exchange_rate);
        }

        $this->assertSame(500.00, round($expected, 2), 'fixture: 250 + 250');

        $this->assertFigure($this->overview(), 'Payables (we owe)', '500.00');
    }

    /**
     * A non-unity rate must be applied per document, which is only possible if the
     * conversion happens inside the aggregate rather than after it.
     */
    public function test_a_foreign_currency_receivable_is_converted_per_document(): void
    {
        SalesInvoice::create([
            'number' => 'INV-'.uniqid(),
            'customer_id' => $this->customer()->id,
            'issue_date' => now()->subDay()->toDateString(),
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => 100,
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'total' => '100.00',
            'paid_amount' => '0',
            'credit_applied' => '0',
            'credit_note_applied' => '0',
        ]);

        $expected = 0.0;
        foreach (SalesInvoice::whereIn('status', ['sent', 'partially_paid', 'overdue'])->get() as $invoice) {
            $expected += to_base_currency($invoice->balance(), $invoice->exchange_rate);
        }

        $this->assertSame(10000.00, round($expected, 2), 'fixture: 100.00 at rate 100');

        $this->assertFigure($this->overview(), 'Receivables (owed to us)', '10,000.00');
    }

    /**
     * Inflows and outflows are the reason the totals moved; the range filter must
     * still hold.
     */
    public function test_inflows_and_outflows_respect_the_date_range(): void
    {
        $customer = $this->customer();
        $supplier = $this->supplier('Range Supplier');

        $invoice = SalesInvoice::create([
            'number' => 'INV-'.uniqid(),
            'customer_id' => $customer->id,
            'issue_date' => now()->subDays(40)->toDateString(),
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '1000.00',
            'tax_amount' => '0.00',
            'total' => '1000.00',
            'paid_amount' => '0',
            'credit_applied' => '0',
            'credit_note_applied' => '0',
        ]);

        // The activity feed prints the payment's reference, falling back to the
        // invoice number -- not the payment number -- so the marker has to be the
        // reference. A bare amount is no use either: "600" also occurs in dates,
        // ids and stylesheet values elsewhere in the page, so an amount-based
        // negative assertion passes or fails by accident.
        $receiptReference = 'RCPT-'.uniqid();
        $receipt = SalesPayment::create([
            'number' => 'PAY-RANGE-'.uniqid(),
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'amount' => '1000.00',
            'payment_date' => now()->subDays(5)->toDateString(),
            'method' => 'cash',
            'reference' => $receiptReference,
            'currency' => 'USD',
            'exchange_rate' => 1,
        ]);

        $bill = PurchaseInvoice::create([
            'number' => 'BILL-'.uniqid(),
            'supplier_id' => $supplier->id,
            'issue_date' => now()->subDays(40)->toDateString(),
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '600.00',
            'tax_amount' => '0.00',
            'total' => '600.00',
            'paid_amount' => '0',
        ]);

        $paymentReference = 'PAID-'.uniqid();
        $payment = SupplierPayment::create([
            'number' => 'SP-RANGE-'.uniqid(),
            'invoice_id' => $bill->id,
            'supplier_id' => $supplier->id,
            'amount' => '600.00',
            'payment_date' => now()->subDays(5)->toDateString(),
            'method' => 'cash',
            'reference' => $paymentReference,
            'currency' => 'USD',
            'exchange_rate' => 1,
        ]);

        $wide = 'from='.now()->subDays(30)->toDateString().'&to='.now()->toDateString();
        $narrow = 'from='.now()->subDays(3)->toDateString().'&to='.now()->toDateString();

        $this->actingAs($this->admin())
            ->get('/cash-flow/inflows?'.$wide)
            ->assertOk()
            ->assertSee($receiptReference);

        $this->actingAs($this->admin())
            ->get('/cash-flow/outflows?'.$wide)
            ->assertOk()
            ->assertSee($paymentReference);

        // Both payments are five days old, so a three-day window excludes them.
        $this->actingAs($this->admin())
            ->get('/cash-flow/inflows?'.$narrow)
            ->assertOk()
            ->assertDontSee($receiptReference);

        $this->actingAs($this->admin())
            ->get('/cash-flow/outflows?'.$narrow)
            ->assertOk()
            ->assertDontSee($paymentReference);
    }

    /**
     * Every cash-flow screen still renders for an authorised user after the totals
     * were rewritten.
     */
    public function test_every_cash_flow_screen_renders(): void
    {
        $account = $this->bankAccount('Render Bank', '500.00');
        $this->transaction($account, 'deposit', '10.00', now()->toDateString());

        foreach (['inflows', 'outflows', 'forecast', 'reports'] as $screen) {
            $this->actingAs($this->admin())
                ->get("/cash-flow/{$screen}")
                ->assertOk();
        }
    }
}
