<?php

namespace Tests\Feature;

use App\Models\SalesCustomer;
use App\Models\SalesInvoice;
use App\Models\SalesPayment;
use App\Models\User;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Overpayment and customer credit.
 *
 * The scenario this exists for: a customer is on file owing 5,000 from an
 * opening balance, an invoice adds 4,000, and they then hand over 11,000. That
 * used to be refused outright — `lockAndGuard` threw "exceeds the outstanding
 * balance" — so the only ways a customer could hold credit were a negative
 * opening balance or a payment recorded with no invoice. A customer paying too
 * much was a dead end: the money could not be received at all.
 *
 * Odoo's behaviour, which this follows: the invoice takes what it is owed, the
 * receipt is settled, and the surplus stays on the partner account as a
 * prepayment that later invoices consume automatically.
 *
 * The confirmation is not decoration. Without `overpayment_confirmed` the strict
 * guard still throws, so a mistyped amount cannot reach the ledger because
 * someone dismissed a dialog.
 */
class CustomerCreditTest extends TestCase
{
    use SeedsDatabase;

    private ?User $admin = null;

    /**
     * Memoised: `userForRole` creates a user, so a test calling it twice collides
     * on the seeded admin's unique email instead of reusing the row.
     */
    private function admin(): User
    {
        return $this->admin ??= $this->userForRole('Admin');
    }

    private function customer(array $attributes = []): SalesCustomer
    {
        return SalesCustomer::create(array_merge([
            'company_name' => 'Nexos Digital '.uniqid(),
            'currency' => 'PKR',
            'is_active' => true,
            'opening_balance' => 0,
        ], $attributes));
    }

    private function invoice(SalesCustomer $customer, float $total = 4000.00, string $status = 'sent'): SalesInvoice
    {
        return SalesInvoice::create([
            'number' => 'INV-'.uniqid(),
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'status' => $status,
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => (string) $total,
            'tax_amount' => '0',
            'total' => (string) $total,
            'paid_amount' => '0',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function paymentPayload(SalesInvoice $invoice, string $amount, array $overrides = []): array
    {
        return array_merge([
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'amount' => $amount,
            'payment_date' => now()->toDateString(),
            'method' => 'bank_transfer',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function invoicePayload(SalesCustomer $customer, string $unitPrice, array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'status' => 'sent',
            'items' => [[
                'description' => 'Purchase',
                'qty' => '1',
                'unit_price' => $unitPrice,
                'discount_percent' => '0',
                'tax_percent' => '0',
            ]],
        ], $overrides);
    }

    private function latestInvoiceFor(SalesCustomer $customer): SalesInvoice
    {
        return SalesInvoice::where('customer_id', $customer->id)->orderByDesc('id')->firstOrFail();
    }

    public function test_an_unconfirmed_overpayment_is_still_refused(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer, 1000);

        $this->actingAs($this->admin())
            ->post('/sales/payment-in', $this->paymentPayload($invoice, '1500.00'))
            ->assertSessionHas('toasts');

        $this->assertSame(0, SalesPayment::count(), 'nothing may be recorded without confirmation');
        $this->assertEqualsWithDelta(0.0, (float) $invoice->fresh()->paid_amount, 0.0005);
    }

    public function test_a_confirmed_overpayment_settles_the_invoice_and_holds_the_surplus_as_credit(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer, 1000);

        $this->actingAs($this->admin())
            ->post('/sales/payment-in', $this->paymentPayload($invoice, '1500.00', [
                'overpayment_confirmed' => 1,
            ]))
            ->assertSessionHasNoErrors();

        $invoice->refresh();

        $this->assertEqualsWithDelta(1000.0, (float) $invoice->paid_amount, 0.0005);
        $this->assertSame('paid', $invoice->status, 'the invoice is settled in full');
        $this->assertEqualsWithDelta(0.0, $invoice->balance(), 0.0005);

        $this->assertEqualsWithDelta(
            500.0,
            $customer->fresh()->creditAvailable(),
            0.0005,
            'the surplus belongs to the customer, not to us',
        );
        $this->assertEqualsWithDelta(-500.0, $customer->fresh()->balance(), 0.0005);

        // Two receipts: one settling the bill, one standing on account. The
        // unallocated row is what makes the credit real rather than a number.
        $this->assertSame(2, SalesPayment::count());
        $this->assertEqualsWithDelta(
            500.0,
            (float) SalesPayment::whereNull('invoice_id')->value('amount'),
            0.0005,
        );
    }

    /**
     * The scenario from the report, end to end: 5,000 owed on file, a 4,000 bill,
     * then 11,000 handed over.
     */
    public function test_the_reported_opening_balance_plus_invoice_scenario(): void
    {
        $customer = $this->customer(['opening_balance' => 5000, 'credit_limit' => 10000]);
        $invoice = $this->invoice($customer, 4000);

        $this->assertEqualsWithDelta(
            9000.0,
            $customer->fresh()->balance(),
            0.0005,
            'the opening balance and the bill are both outstanding',
        );

        $this->actingAs($this->admin())
            ->post('/sales/payment-in', $this->paymentPayload($invoice, '11000.00', [
                'overpayment_confirmed' => 1,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertEqualsWithDelta(
            2000.0,
            $customer->fresh()->creditAvailable(),
            0.0005,
            '9,000 clears the ledger and 2,000 stays with the customer',
        );
    }

    public function test_the_next_invoice_consumes_the_credit_automatically(): void
    {
        $customer = $this->customer();
        $this->actingAs($this->admin())
            ->post('/sales/payment-in', $this->paymentPayload($this->invoice($customer, 1000), '2500.00', [
                'overpayment_confirmed' => 1,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(1500.0, $customer->fresh()->creditAvailable(), 0.0005);

        $this->actingAs($this->admin())
            ->post('/sales/invoices', $this->invoicePayload($customer, '900.00'))
            ->assertSessionHasNoErrors();

        $next = $this->latestInvoiceFor($customer);

        $this->assertEqualsWithDelta(
            900.0,
            (float) $next->credit_applied,
            0.0005,
            'the credit settles the bill without any cash changing hands',
        );
        $this->assertSame('paid', $next->status, 'so no money is requested');
        $this->assertEqualsWithDelta(0.0, $next->balance(), 0.0005);
        $this->assertEqualsWithDelta(
            600.0,
            $customer->fresh()->creditAvailable(),
            0.0005,
            'the remainder carries forward',
        );
    }

    public function test_credit_never_exceeds_what_the_customer_actually_holds(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->admin())
            ->post('/sales/payment-in', $this->paymentPayload($this->invoice($customer, 1000), '1200.00', [
                'overpayment_confirmed' => 1,
            ]))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin())
            ->post('/sales/invoices', $this->invoicePayload($customer, '5000.00'))
            ->assertSessionHasNoErrors();

        $next = $this->latestInvoiceFor($customer);

        $this->assertEqualsWithDelta(
            200.0,
            (float) $next->credit_applied,
            0.0005,
            'only the 200 actually held can be applied',
        );
        $this->assertSame('partially_paid', $next->status);
        $this->assertEqualsWithDelta(4800.0, $next->balance(), 0.0005);
    }

    public function test_shrinking_an_invoice_returns_the_credit_it_no_longer_needs(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->admin())
            ->post('/sales/payment-in', $this->paymentPayload($this->invoice($customer, 1000), '1300.00', [
                'overpayment_confirmed' => 1,
            ]))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin())
            ->post('/sales/invoices', $this->invoicePayload($customer, '250.00'))
            ->assertSessionHasNoErrors();

        $next = $this->latestInvoiceFor($customer);
        $this->assertEqualsWithDelta(250.0, (float) $next->credit_applied, 0.0005);

        $this->actingAs($this->admin())
            ->put("/sales/invoices/{$next->id}", $this->invoicePayload($customer, '40.00'))
            ->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(
            40.0,
            (float) $next->fresh()->credit_applied,
            0.0005,
            'the invoice keeps only what its new total needs',
        );
        $this->assertEqualsWithDelta(
            260.0,
            $customer->fresh()->creditAvailable(),
            0.0005,
            'and the freed credit returns to the customer',
        );
    }

    public function test_deleting_an_invoice_does_not_swallow_the_credit(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->admin())
            ->post('/sales/payment-in', $this->paymentPayload($this->invoice($customer, 1000), '1300.00', [
                'overpayment_confirmed' => 1,
            ]))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin())
            ->post('/sales/invoices', $this->invoicePayload($customer, '200.00'))
            ->assertSessionHasNoErrors();

        $next = $this->latestInvoiceFor($customer);
        $this->assertEqualsWithDelta(200.0, (float) $next->credit_applied, 0.0005);
        $this->assertEqualsWithDelta(100.0, $customer->fresh()->creditAvailable(), 0.0005);

        $this->actingAs($this->admin())
            ->delete("/sales/invoices/{$next->id}")
            ->assertSessionHas('toasts');

        $this->assertEqualsWithDelta(
            300.0,
            $customer->fresh()->creditAvailable(),
            0.0005,
            'a deleted invoice must hand its credit back',
        );
    }

    /**
     * Regression: paid invoices used to be excluded from the billed figure while
     * their payments still counted against the balance, so a customer who had
     * paid everything in full appeared to be holding credit equal to their
     * invoices. A ledger that invents money is worse than one that refuses to.
     */
    public function test_a_fully_paid_customer_does_not_appear_to_hold_credit(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer, 4000);

        $this->actingAs($this->admin())
            ->post('/sales/payment-in', $this->paymentPayload($invoice, '4000.00'))
            ->assertSessionHasNoErrors();

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertEqualsWithDelta(
            0.0,
            $customer->fresh()->balance(),
            0.0005,
            'nothing owed, nothing held',
        );
        $this->assertEqualsWithDelta(0.0, $customer->fresh()->creditAvailable(), 0.0005);
    }

    public function test_a_cash_payment_cannot_collect_money_credit_already_settled(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->admin())
            ->post('/sales/payment-in', $this->paymentPayload($this->invoice($customer, 1000), '1200.00', [
                'overpayment_confirmed' => 1,
            ]))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin())
            ->post('/sales/invoices', $this->invoicePayload($customer, '200.00'))
            ->assertSessionHasNoErrors();

        $next = $this->latestInvoiceFor($customer);
        $this->assertEqualsWithDelta(0.0, $next->balance(), 0.0005);

        // The credit already settled it, so a further receipt has nothing to do
        // and must not be accepted.
        $this->actingAs($this->admin())
            ->post('/sales/payment-in', $this->paymentPayload($next, '200.00'))
            ->assertSessionHas('toasts');

        $this->assertEqualsWithDelta(0.0, (float) $next->fresh()->paid_amount, 0.0005);
    }

    public function test_credit_is_left_alone_on_a_draft_invoice(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->admin())
            ->post('/sales/payment-in', $this->paymentPayload($this->invoice($customer, 1000), '1300.00', [
                'overpayment_confirmed' => 1,
            ]))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin())
            ->post('/sales/invoices', $this->invoicePayload($customer, '500.00', ['status' => 'draft']))
            ->assertSessionHasNoErrors();

        $draft = $this->latestInvoiceFor($customer);

        $this->assertEqualsWithDelta(
            0.0,
            (float) $draft->credit_applied,
            0.0005,
            'a draft is not a demand for money yet, so credit is left alone',
        );
        $this->assertSame('draft', $draft->status);
        $this->assertEqualsWithDelta(300.0, $customer->fresh()->creditAvailable(), 0.0005);
    }
}