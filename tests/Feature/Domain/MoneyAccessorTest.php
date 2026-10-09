<?php

namespace Tests\Feature\Domain;

use App\Models\ExchangeRate;
use App\Models\SalesInvoice;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Arithmetic on the model accessors that every screen and report reads from.
 *
 * These are the pure functions behind the money figures — a defect here is
 * invisible in a page snapshot test but shows up as a wrong total on an
 * invoice, a statement or a dashboard tile.
 */
class MoneyAccessorTest extends TestCase
{
    use SeedsDatabase;

    private function invoice(array $attributes = []): SalesInvoice
    {
        return SalesInvoice::create(array_merge([
            'number' => next_document_number('invoice', 'INV'),
            'customer_id' => $this->customer('Accessor Co')->id,
            'issue_date' => now()->toDateString(),
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
        ], $attributes));
    }

    public function test_balance_subtracts_everything_already_settled(): void
    {
        $invoice = $this->invoice([
            'subtotal' => 1000,
            'tax_amount' => 100,
            'total' => 1100,
            'paid_amount' => 400,
            'credit_applied' => 50,
            'credit_note_applied' => 25,
        ]);

        $this->assertSame(625.0, (float) $invoice->balance());
    }

    public function test_balance_never_rounds_away_a_cent(): void
    {
        // 0.1 + 0.2 style drift must not surface as 0.30 vs 0.29999.
        $invoice = $this->invoice([
            'total' => 10.10,
            'paid_amount' => 0.30,
        ]);

        $this->assertSame(9.8, (float) $invoice->balance());
        $this->assertSame('9.80', number_format((float) $invoice->balance(), 2, '.', ''));
    }

    public function test_is_paid_requires_the_full_balance_to_be_settled(): void
    {
        $invoice = $this->invoice(['total' => 100, 'paid_amount' => 99.99]);

        $this->assertFalse($invoice->isPaid());

        $invoice->update(['paid_amount' => 100]);
        $this->assertTrue($invoice->fresh()->isPaid());

        // Credit settles an invoice exactly as cash does.
        $invoice->update(['paid_amount' => 0, 'credit_applied' => 100]);
        $this->assertTrue($invoice->fresh()->isPaid());
    }

    public function test_a_single_currency_customer_balance_is_exact(): void
    {
        $customer = $this->customer('Single Currency Co');

        foreach ([100.10, 200.20, 300.30] as $index => $total) {
            $this->invoice([
                'number' => 'SC-'.$index,
                'customer_id' => $customer->id,
                'total' => $total,
                'subtotal' => $total,
                'status' => 'sent',
            ]);
        }

        $this->assertSame(600.6, (float) $customer->fresh()->balance());
    }

    public function test_paid_invoices_still_count_toward_the_balance(): void
    {
        // README bug fix: paid invoices used to be excluded from the billed
        // sum while their payments were still subtracted, understating the
        // balance.
        $customer = $this->customer('Paid Included Co');

        $invoice = $this->invoice([
            'number' => 'PI-1',
            'customer_id' => $customer->id,
            'total' => 500,
            'subtotal' => 500,
            'status' => 'paid',
            'paid_amount' => 500,
        ]);

        $invoice->payments()->create([
            'number' => next_document_number('payment', 'PAY'),
            'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(),
            'method' => 'cash',
            'amount' => 500,
            'currency' => 'USD',
        ]);

        $this->assertSame(
            0.0,
            (float) $customer->fresh()->balance(),
            'A fully paid invoice must leave a zero balance, not a negative one.',
        );
    }

    public function test_exchange_rate_lookup_falls_back_to_one_for_base_currency(): void
    {
        $this->assertSame(1.0, exchange_rate_for(base_currency()));
    }

    public function test_exchange_rate_lookup_returns_the_stored_rate(): void
    {
        ExchangeRate::create([
            'currency_code' => 'EUR',
            'rate_to_base' => '1.08',
            'effective_date' => now()->toDateString(),
        ]);

        $this->assertSame(1.08, exchange_rate_for('EUR'));
    }

    public function test_base_currency_conversion_rounds_to_two_places(): void
    {
        $this->assertSame(108.0, to_base_currency(100, 1.08));
        $this->assertSame(33.33, to_base_currency(30.86, 1.08));
    }
}