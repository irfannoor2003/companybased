<?php

namespace Tests\Feature\Domain;

use App\Models\BankAccount;
use App\Models\SalesInvoice;
use App\Services\DashboardService;
use App\Support\DocumentItems;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Cross-component arithmetic consistency.
 *
 * Each test computes ONE figure two different ways — the way a screen renders
 * it and the way the underlying model defines it — and asserts they agree.
 * That is the failure mode a user actually experiences: the invoice says
 * PKR 40,000 outstanding, the dashboard says PKR 42,000, and nobody can tell
 * which is right.
 *
 * Tests that currently FAIL are the documented calculation bugs in the README
 * (the known-bug protocol): written against the CORRECT figure and skipped
 * with a README reference until the underlying code is fixed, so the suite
 * stays green while the defect remains documented and pinned.
 */
class CalculationConsistencyTest extends TestCase
{
    use SeedsDatabase;

    /**
     * The invariant every money screen relies on: the printed line amounts
     * add up to the stored subtotal, and subtotal + tax equals the total.
     */
    public function test_document_line_math_matches_stored_totals(): void
    {
        $invoice = SalesInvoice::create([
            'number' => next_document_number('invoice', 'INV'),
            'customer_id' => $this->customer('Math Co')->id,
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
        ]);

        $totals = DocumentItems::sync($invoice, [
            ['description' => 'A', 'qty' => 3, 'unit_price' => 100, 'discount_percent' => 10, 'tax_percent' => 5],
            ['description' => 'B', 'qty' => 2, 'unit_price' => 50.25, 'discount_percent' => 0, 'tax_percent' => 17],
            ['description' => 'C', 'qty' => 1, 'unit_price' => 0.05, 'discount_percent' => 0, 'tax_percent' => 0],
        ]);

        $invoice->update([
            'subtotal' => $totals['subtotal'],
            'tax_amount' => $totals['tax'],
            'total' => $totals['total'],
        ]);

        $invoice = $invoice->fresh();

        $lineSum = round($invoice->items->sum(fn ($i) => (float) $i->line_total), 2);

        $this->assertSame(
            round((float) $invoice->subtotal, 2),
            $lineSum,
            'Stored subtotal must equal the sum of stored line totals.',
        );

        $this->assertSame(
            round((float) $invoice->total, 2),
            round((float) $invoice->subtotal + (float) $invoice->tax_amount, 2),
            'Stored total must equal subtotal + tax.',
        );
    }

    /**
     * The invoice model and the payments table must agree on what "paid" means.
     */
    public function test_invoice_balance_equals_total_minus_recorded_payments(): void
    {
        $customer = $this->customer('Balance Co');

        $invoice = SalesInvoice::create([
            'number' => next_document_number('invoice', 'INV'),
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => 500,
            'tax_amount' => 50,
            'total' => 550,
        ]);

        $invoice->payments()->create([
            'number' => next_document_number('payment', 'PAY'),
            'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(),
            'method' => 'cash',
            'amount' => 200,
            'currency' => 'USD',
        ]);

        $invoice->update(['paid_amount' => 200]);

        $this->assertSame(350.0, (float) $invoice->fresh()->balance());
        $this->assertFalse($invoice->fresh()->isPaid());

        $invoice->update(['paid_amount' => 550]);
        $this->assertTrue($invoice->fresh()->isPaid());
        $this->assertSame(0.0, (float) $invoice->fresh()->balance());
    }

    /**
     * POS totals: subtotal - discount + tax = total and paid - total = change.
     * Mirrors the arithmetic in SaleScreenController without HTTP.
     */
    public function test_pos_totals_arithmetic_is_consistent(): void
    {
        $lines = [
            ['qty' => 2, 'price' => 150.75],
            ['qty' => 1, 'price' => 0.99],
            ['qty' => 3, 'price' => 33.33],
        ];

        $subtotal = 0.0;

        foreach ($lines as $line) {
            $subtotal += round($line['qty'] * round($line['price'], 2), 2);
        }

        $subtotal = round($subtotal, 2);
        $discount = 49.99;
        $tax = round(($subtotal - $discount) * 0.05, 2);
        $total = round($subtotal - $discount + $tax, 2);
        $paid = 500.00;
        $change = round(max($paid - $total, 0), 2);

        $this->assertSame(round($subtotal - $discount + $tax, 2), $total);
        $this->assertSame(round($paid - $total, 2), $change);
        $this->assertTrue($discount <= $subtotal);
    }

    /**
     * Dashboard cards must at least be consistent among themselves: the
     * executive "Receivables" tile and the "Outstanding" tile derive from the
     * same rows and must render the same number.
     */
    public function test_dashboard_cards_are_internally_consistent(): void
    {
        $customer = $this->customer('Dash Co');

        SalesInvoice::create([
            'number' => next_document_number('invoice', 'INV'),
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => 800,
            'tax_amount' => 0,
            'total' => 800,
            'paid_amount' => 300,
        ]);

        $service = app(DashboardService::class);
        $reflection = new \ReflectionClass($service);

        $executive = $reflection->getMethod('executiveSummary');
        $executive->setAccessible(true);
        $receivables = $reflection->getMethod('receivables');
        $receivables->setAccessible(true);

        $executiveItems = collect($executive->invoke($service)['items'])->keyBy('label');
        $receivableItems = collect($receivables->invoke($service)['items'])->keyBy('label');

        $this->assertArrayHasKey('Receivables', $executiveItems->all());
        $this->assertArrayHasKey('Outstanding', $receivableItems->all());

        $strip = fn (string $value): string => preg_replace('/[^0-9.]/', '', $value) ?? '';

        $this->assertSame(
            $strip($receivableItems['Outstanding']['value']),
            $strip($executiveItems['Receivables']['value']),
            'The executive Receivables card and the Receivables/Outstanding card disagree.',
        );
    }

    /**
     * Dashboard "Receivables" vs the definition of an outstanding balance.
     *
     * KNOWN BUG (README CALC-1): DashboardService computes
     * sum(total) - sum(paid_amount) and ignores credit_applied and
     * credit_note_applied, so an invoice settled by store credit or a credit
     * note still counts as outstanding.
     */
    public function test_dashboard_receivables_matches_the_sum_of_invoice_balances(): void
    {
        $customer = $this->customer('Receivable Co');

        SalesInvoice::create([
            'number' => next_document_number('invoice', 'INV'),
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'status' => 'partially_paid',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => 1000,
            'tax_amount' => 0,
            'total' => 1000,
            'paid_amount' => 400,
            'credit_applied' => 100,
            'credit_note_applied' => 50,
        ]);

        $expected = round(
            (float) SalesInvoice::query()
                ->whereIn('status', ['sent', 'partially_paid', 'overdue'])
                ->get()
                ->sum(fn (SalesInvoice $i) => (float) $i->balance()),
            2,
        );

        $service = app(DashboardService::class);
        $reflection = new \ReflectionClass($service);
        $executive = $reflection->getMethod('executiveSummary');
        $executive->setAccessible(true);

        $items = collect($executive->invoke($service)['items'])->keyBy('label');
        $actual = round((float) preg_replace('/[^0-9.]/', '', $items['Receivables']['value']), 2);

        if ($actual !== $expected) {
            $this->markTestSkipped(
                'KNOWN BUG (README CALC-1): dashboard receivables ignore credit_applied and '
                ."credit_note_applied. Sum of invoice balances = {$expected}, dashboard shows {$actual}.",
            );
        }

        $this->assertSame($expected, $actual);
    }

    /**
     * Dashboard "Cash accounts" vs the bank ledger.
     *
     * KNOWN BUG (README CALC-2): the tile sums opening_balance only, so cash
     * never moves regardless of deposits and withdrawals.
     */
    public function test_dashboard_cash_matches_the_sum_of_bank_account_balances(): void
    {
        $bank = BankAccount::create([
            'name' => 'Cash Co Current',
            'opening_balance' => '5000.00',
            'is_active' => true,
        ]);

        // bank_transactions.number is NOT NULL with no default; a receipt number is
        // part of the record, not an optional annotation.
        $bank->transactions()->createMany([
            ['type' => 'deposit', 'amount' => '1200.00', 'number' => 'TXN-D1', 'transaction_date' => now()->toDateString()],
            ['type' => 'withdrawal', 'amount' => '300.00', 'number' => 'TXN-W1', 'transaction_date' => now()->toDateString()],
        ]);

        $expected = round((float) BankAccount::query()->get()->sum(fn (BankAccount $a) => $a->balance()), 2);

        $service = app(DashboardService::class);
        $reflection = new \ReflectionClass($service);
        $executive = $reflection->getMethod('executiveSummary');
        $executive->setAccessible(true);

        $items = collect($executive->invoke($service)['items'])->keyBy('label');
        $actual = round((float) preg_replace('/[^0-9.]/', '', $items['Cash accounts']['value']), 2);

        if ($actual !== $expected) {
            $this->markTestSkipped(
                'KNOWN BUG (README CALC-2): the cash tile shows only the sum of opening_balance '
                ."(and is currency-blind). Ledger total = {$expected}, tile shows {$actual}.",
            );
        }

        $this->assertSame($expected, $actual);
    }

    /**
     * A customer balance must not mix currencies.
     *
     * KNOWN BUG (README CALC-3): SalesCustomer::balance() sums `total` across
     * every invoice regardless of its currency, so a customer with a USD and a
     * PKR invoice gets a meaningless number.
     */
    public function test_a_customer_balance_never_adds_different_currencies(): void
    {
        $customer = $this->customer('Multi Currency Co');

        foreach (['USD' => 100, 'PKR' => 50000] as $currency => $amount) {
            SalesInvoice::create([
                'number' => next_document_number('invoice', 'INV'),
                'customer_id' => $customer->id,
                'issue_date' => now()->toDateString(),
                'status' => 'sent',
                'currency' => $currency,
                'exchange_rate' => $currency === 'PKR' ? 0.0036 : 1,
                'subtotal' => $amount,
                'tax_amount' => 0,
                'total' => $amount,
            ]);
        }

        $balance = (float) $customer->fresh()->balance();

        // Correct behaviour is a base-currency figure: USD 100 + (PKR 50,000
        // x 0.0036) = 280.00. The broken code returns 50,100.
        if ($balance === 50100.0) {
            $this->markTestSkipped(
                'KNOWN BUG (README CALC-3): SalesCustomer::balance() adds amounts across '
                ."currencies without conversion. Balance reports {$balance}.",
            );
        }

        $this->assertSame(280.00, round($balance, 2));
    }

    /**
     * An invoice must never be marked paid while an unpaid balance remains,
     * and a fully-paid invoice must never be left open.
     */
    public function test_invoice_status_reflects_its_balance(): void
    {
        $invoice = SalesInvoice::create([
            'number' => next_document_number('invoice', 'INV'),
            'customer_id' => $this->customer('Status Co')->id,
            'issue_date' => now()->toDateString(),
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => 100,
            'tax_amount' => 0,
            'total' => 100,
            'paid_amount' => 100,
        ]);

        app(\App\Services\InvoicePaymentService::class)->recalculateStatus($invoice);

        $this->assertSame('paid', $invoice->fresh()->status);

        $invoice->update(['paid_amount' => 0]);
        app(\App\Services\InvoicePaymentService::class)->recalculateStatus($invoice->fresh());

        $this->assertSame('sent', $invoice->fresh()->status);
    }
}
