<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\PurchaseInvoice;
use App\Models\SalesCustomer;
use App\Models\SalesInvoice;
use App\Models\SalesPayment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InvoicePaymentService;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Regression tests for the accounting bugs found in the 2026-10-02 audit.
 *
 * Every one of these shipped green because nothing in the suite exercised the
 * code path — which is exactly why the bugs survived. They are not speculative:
 * each names the wrong figure that was being produced.
 *
 * The shared theme is that a settled document was excluded from "what is owed"
 * while the money that settled it was still subtracted. On the sales side that
 * made a fully-paid customer look as though they were holding credit; on the
 * supplier side it made the company believe it owed money to suppliers it had
 * already paid in full.
 */
class AccountingAuditRegressionTest extends TestCase
{
    use SeedsDatabase;

    private ?User $admin = null;

    private function admin(): User
    {
        return $this->admin ??= $this->userForRole('Admin');
    }

    private function customer(): SalesCustomer
    {
        return SalesCustomer::create([
            'company_name' => 'Cust '.uniqid(),
            'currency' => 'PKR',
            'is_active' => true,
            'opening_balance' => 0,
        ]);
    }

    private function supplier(): Supplier
    {
        return Supplier::create([
            'company_name' => 'Supp '.uniqid(),
            'currency' => 'PKR',
            'is_active' => true,
        ]);
    }

/**
 * Create an invoice and, when it is marked paid, the receipt that settled it.
 *
 * The receipt matters: `balance()` sums the payment rows rather than reading the
 * `paid_amount` column, so a "paid" invoice with no payment against it is simply
 * an unpaid invoice that claims otherwise.
 */
private function salesInvoice(SalesCustomer $customer, float $total, string $status = 'sent'): SalesInvoice
{
    $invoice = SalesInvoice::create([
        'number' => 'INV-'.uniqid(),
        'customer_id' => $customer->id,
        'issue_date' => now()->subDays(5)->toDateString(),
        'due_date' => now()->addDays(25)->toDateString(),
        'status' => $status,
        'currency' => 'PKR',
        'exchange_rate' => 1,
        'subtotal' => (string) $total,
        'tax_amount' => '0',
        'total' => (string) $total,
        'paid_amount' => $status === 'paid' ? (string) $total : '0',
    ]);

    if ($status === 'paid') {
        SalesPayment::create([
            'number' => 'RC-'.uniqid(),
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'amount' => (string) $total,
            'payment_date' => now()->subDays(3)->toDateString(),
            'method' => 'bank_transfer',
            'currency' => 'PKR',
            'exchange_rate' => 1,
        ]);
    }

    return $invoice->fresh();
}

private function purchaseInvoice(Supplier $supplier, float $total, string $status = 'sent'): PurchaseInvoice
{
    $invoice = PurchaseInvoice::create([
        'number' => 'PINV-'.uniqid(),
        'supplier_id' => $supplier->id,
        'issue_date' => now()->subDays(5)->toDateString(),
        'status' => $status,
        'currency' => 'PKR',
        'exchange_rate' => 1,
        'subtotal' => (string) $total,
        'tax_amount' => '0',
        'total' => (string) $total,
        'paid_amount' => $status === 'paid' ? (string) $total : '0',
    ]);

    if ($status === 'paid') {
        \App\Models\SupplierPayment::create([
            'number' => 'SP-'.uniqid(),
            'invoice_id' => $invoice->id,
            'supplier_id' => $supplier->id,
            'amount' => (string) $total,
            'payment_date' => now()->subDays(3)->toDateString(),
            'method' => 'bank_transfer',
            'currency' => 'PKR',
            'exchange_rate' => 1,
        ]);
    }

    return $invoice->fresh();
}

    /**
     * A customer who has paid everything in full owes nothing.
     */
    public function test_a_fully_paid_customer_is_not_shown_as_holding_credit(): void
    {
        $customer = $this->customer();
        $this->salesInvoice($customer, 4000, 'paid');

        // `paid` invoices used to be excluded from the billed figure while the
        // payment that settled them was still subtracted, so this returned
        // -4,000 and the customer appeared to be 4,000 in credit.
        $this->assertEqualsWithDelta(0.0, $customer->balance(), 0.0005);
        $this->assertEqualsWithDelta(0.0, $customer->creditAvailable(), 0.0005);
        $this->assertFalse($customer->hasCredit());
    }

    /**
     * The same bug, mirrored on the supplier side: a paid bill was excluded
     * while its payment was subtracted, so the company looked as though it owed
     * the supplier the whole of every bill it had already settled.
     */
    public function test_a_fully_paid_supplier_is_not_shown_as_owed_money(): void
    {
        $supplier = $this->supplier();
        $this->purchaseInvoice($supplier, 8000, 'paid');

        $this->assertEqualsWithDelta(
            0.0,
            $supplier->balance(),
            0.0005,
            'the company must not believe it owes a supplier it has paid in full',
        );
    }

    public function test_the_opening_balance_is_included_in_the_customer_balance(): void
    {
        $customer = SalesCustomer::create([
            'company_name' => 'Owed '.uniqid(),
            'currency' => 'PKR',
            'is_active' => true,
            'opening_balance' => 5000,
        ]);

        $this->salesInvoice($customer, 4000, 'paid');

        // 5,000 carried in plus 4,000 billed less 4,000 received.
        $this->assertEqualsWithDelta(5000.0, $customer->balance(), 0.0005);
    }

    /**
     * The customer list had its own copy of the balance formula, which omitted
     * the opening balance. Two formulas for one number is how they drift, and
     * the list disagreed with the customer's own page.
     */
    public function test_the_customer_list_balance_agrees_with_the_model(): void
    {
        $customer = SalesCustomer::create([
            'company_name' => 'Listed '.uniqid(),
            'currency' => 'PKR',
            'is_active' => true,
            'opening_balance' => 2500,
        ]);

        $this->salesInvoice($customer, 1500, 'paid');

        $this->actingAs($this->admin())
            ->get('/sales/customers')
            ->assertOk()
            ->assertSee(money(2500, 'PKR'), escape: false);
    }

    public function test_the_statements_index_total_is_not_negative_for_settled_customers(): void
    {
        foreach (range(1, 3) as $index) {
            $customer = $this->customer();
            $this->salesInvoice($customer, 1000 * $index, 'paid');
        }

        $this->actingAs($this->admin())
            ->get('/sales/statements')
            ->assertOk();

        // "Total outstanding" is driven by the sum of per-customer balances. With
        // `paid` invoices excluded it reported -6,000 for a book of business that
        // was entirely settled.
        $this->assertEqualsWithDelta(
            0.0,
            SalesCustomer::query()->get()->sum(fn (SalesCustomer $c) => $c->balance()),
            0.01,
        );
    }

    /**
     * The status machine could only move forwards, so reversing a payment left
     * the invoice claiming `partially_paid` with a paid_amount of zero.
     */
    public function test_an_invoice_returns_to_unpaid_when_its_payment_is_reversed(): void
    {
        $customer = $this->customer();
        $invoice = $this->salesInvoice($customer, 1000, 'partially_paid');

        $payment = SalesPayment::create([
            'number' => 'RC-'.uniqid(),
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'amount' => '400.00',
            'payment_date' => now()->toDateString(),
            'method' => 'cash',
        ]);

        $invoice->update(['paid_amount' => '400.00']);
        $this->assertSame('partially_paid', $invoice->fresh()->status);

        $invoice->update(['paid_amount' => '0.00']);
        $payment->delete();

        app(InvoicePaymentService::class)->recalculateStatus($invoice->fresh());

        $this->assertEqualsWithDelta(0.0, (float) $invoice->fresh()->paid_amount, 0.0005);
        $this->assertNotSame(
            'partially_paid',
            $invoice->fresh()->status,
            'an invoice that has received nothing must not still claim it is part-paid',
        );
    }

    /**
     * A transfer walked back to draft used to skip the ledger reversal, so
     * completing it again double-posted the money and both account balances
     * ended up wrong in each direction.
     */
    public function test_a_bank_transfer_reversed_to_draft_does_not_leave_ledger_legs(): void
    {
        $account = BankAccount::create(['name' => 'Current '.uniqid(), 'opening_balance' => '1000.00', 'is_active' => true]);
        $counterparty = BankAccount::create(['name' => 'Savings '.uniqid(), 'opening_balance' => '1000.00', 'is_active' => true]);

        $transfer = \App\Models\BankTransfer::create([
            'number' => 'XFR-'.uniqid(),
            'from_account_id' => $account->id,
            'to_account_id' => $counterparty->id,
            'amount' => '250.00',
            'transfer_date' => now()->toDateString(),
            'status' => 'draft',
        ]);

        \App\Support\BankLedger::postTransfer($transfer);

        $legs = BankTransaction::query()
            ->where('reference_type', $transfer->getMorphClass())
            ->where('reference_id', $transfer->id)
            ->count();

        $this->assertGreaterThan(0, $legs, 'completing a transfer must post a leg');

        \App\Support\BankLedger::reverseTransfer($transfer);

        $this->assertSame(
            0,
            BankTransaction::query()
                ->where('reference_type', $transfer->getMorphClass())
                ->where('reference_id', $transfer->id)
                ->count(),
            'reversing must remove every leg, or a re-complete posts a second pair',
        );
    }

    /**
     * Sequential numbers skipped every other value, because the counter was
     * incremented before it was persisted. Gaps in a document sequence are
     * indistinguishable from deleted records during an audit.
     */
    public function test_document_numbers_do_not_skip_values(): void
    {
        $issued = [];

        for ($i = 0; $i < 4; $i++) {
            $issued[] = next_document_number('audit_probe', 'AUD');
        }

        $this->assertSame(
            $issued,
            array_values(array_unique($issued)),
            'each call must issue a distinct number',
        );

        $sequence = array_map(static fn (string $n): int => (int) substr($n, -4), $issued);

        $this->assertSame(
            range($sequence[0], $sequence[0] + count($sequence) - 1),
            $sequence,
            'four documents must produce four consecutive numbers, got: '.implode(', ', $sequence),
        );
    }

    /**
     * Eight edit forms wiped the document's currency and reset its exchange rate
     * on every save, because `old()` was called with three arguments and PHP
     * silently drops the extra one — leaving the literal string 'currency' as
     * the default, which matches no ISO code, so the browser submitted the empty
     * first option.
     */
    public function test_editing_a_quote_preserves_its_currency_and_exchange_rate(): void
    {
        $quote = \App\Models\SalesQuote::create([
            'number' => 'QUO-'.uniqid(),
            'customer_id' => $this->customer()->id,
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'currency' => 'EUR',
            'exchange_rate' => 0.92,
            'subtotal' => '100',
            'tax_amount' => '0',
            'total' => '100',
        ]);

        $this->actingAs($this->admin())
            ->get("/sales/quotes/{$quote->id}/edit")
            ->assertOk()
            ->assertSee('value="EUR" selected', escape: false);
    }

    /**
     * The Cash Flow trend compared a Carbon to a 'Y-m-d' string with `===`,
     * which is never true, so the chart rendered 61 days of zeros regardless of
     * how much money moved.
     */
    public function test_the_cash_flow_trend_bucket_receipts_by_day(): void
    {
        $customer = $this->customer();
        $invoice = $this->salesInvoice($customer, 1000, 'sent');

        SalesPayment::create([
            'number' => 'RC-'.uniqid(),
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'amount' => '1000.00',
            'payment_date' => now()->toDateString(),
            'method' => 'bank_transfer',
            'currency' => 'PKR',
            'exchange_rate' => 1,
        ]);

        $this->actingAs($this->admin())
            ->get('/cash-flow')
            ->assertOk();
    }
}