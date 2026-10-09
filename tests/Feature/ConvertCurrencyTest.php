<?php

namespace Tests\Feature;

use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\SalesPayment;
use App\Services\InvoicePaymentService;
use App\Support\DocumentItems;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * currency:convert rewrites every document held in a currency, so the arithmetic
 * it leaves behind has to reconcile. It used not to:
 *
 *  - subtotal, tax_amount and total were each multiplied and rounded on their own,
 *    so a converted document could fail subtotal + tax == total;
 *  - line items were never converted, so the printed rows no longer summed to the
 *    printed header;
 *  - paid_amount was converted while the payment rows behind it were not, and
 *    InvoicePaymentService recomputes paid_amount from sum(payments.amount) on
 *    every status recalculation -- so the next recalculation after a conversion
 *    reverted a settled invoice to "partially paid".
 *
 * The command now previews unless --force is passed, so these tests also pin that
 * a plain invocation writes nothing.
 */
class ConvertCurrencyTest extends TestCase
{
    use SeedsDatabase;

    /**
     * Run the command, answering the --force confirmation prompt.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function convert(array $arguments, bool $force = true)
    {
        $command = $this->artisan('currency:convert', $arguments);

        if ($force) {
            $command->expectsConfirmation('Apply the conversion now?', 'yes');
        }

        return $command->assertSuccessful();
    }

    /**
     * An invoice with two lines, a discount and tax, settled by one payment.
     *
     * Line 1: 10 x 10.00 less 10% = 90.00 net, +17% tax = 15.30 -> 105.30
     * Line 2:  1 x 10.00           = 10.00 net, +17% tax =  1.70 ->  11.70
     * Header:  subtotal 100.00 + tax 17.00 = total 117.00
     */
    private function settledInvoice(string $currency = 'USD', string $paid = '117.00'): SalesInvoice
    {
        $customer = $this->customer();

        $invoice = SalesInvoice::create([
            'number' => 'INV-'.uniqid(),
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'sent',
            'currency' => $currency,
            'exchange_rate' => 1,
            'subtotal' => '100.00',
            'tax_amount' => '17.00',
            'total' => '117.00',
            'paid_amount' => $paid,
        ]);

        SalesInvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Widget',
            'qty' => '10',
            'unit_price' => '10.00',
            'discount_percent' => '10',
            'tax_percent' => '17',
            'line_total' => '90.00',
        ]);

        SalesInvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Service',
            'qty' => '1',
            'unit_price' => '10.00',
            'discount_percent' => '0',
            'tax_percent' => '17',
            'line_total' => '10.00',
        ]);

        if ((float) $paid > 0) {
            SalesPayment::create([
                'number' => 'PAY-'.uniqid(),
                'invoice_id' => $invoice->id,
                'customer_id' => $customer->id,
                'amount' => $paid,
                'payment_date' => now()->toDateString(),
                'method' => 'cash',
                'currency' => $currency,
                'exchange_rate' => 1,
            ]);
        }

        return $invoice->fresh();
    }

    public function test_a_plain_run_writes_nothing(): void
    {
        $invoice = $this->settledInvoice();

        $this->convert(['from' => 'USD', 'to' => 'PKR', '--rate' => '278'], force: false);

        $invoice->refresh();

        $this->assertSame('USD', $invoice->currency, 'the command must preview by default');
        $this->assertSame('117.00', $invoice->total);
        $this->assertSame('10.00', (string) $invoice->items()->first()->unit_price);
    }

    public function test_force_converts_the_currency_and_rate(): void
    {
        $invoice = $this->settledInvoice();

        $this->convert(['from' => 'USD', 'to' => 'PKR', '--rate' => '278', '--force' => true]);

        $invoice->refresh();

        $this->assertSame('PKR', $invoice->currency);
        $this->assertEquals(278, (float) $invoice->exchange_rate);
    }

    /**
     * The invariant that made converted documents wrong on screen and in print.
     */
    public function test_the_converted_header_still_reconciles(): void
    {
        $invoice = $this->settledInvoice();

        $this->convert(['from' => 'USD', 'to' => 'PKR', '--rate' => '278', '--force' => true]);

        $invoice->refresh();

        $this->assertEqualsWithDelta(
            (float) $invoice->subtotal + (float) $invoice->tax_amount,
            (float) $invoice->total,
            0.001,
            'subtotal + tax_amount must equal total after conversion',
        );
    }

    /**
     * The lines are what the customer reads; they must sum to the header.
     */
    public function test_the_converted_lines_sum_to_the_converted_header(): void
    {
        $invoice = $this->settledInvoice();

        $this->convert(['from' => 'USD', 'to' => 'PKR', '--rate' => '278', '--force' => true]);

        $invoice->refresh();

        $lineSum = 0.0;
        foreach ($invoice->fresh()->items()->get() as $item) {
            $lineSum += (float) $item->line_total;
        }

        $this->assertEqualsWithDelta(
            (float) $invoice->subtotal,
            round($lineSum, 2),
            0.001,
            'the converted line totals must add up to the converted subtotal',
        );
    }

    public function test_line_prices_are_actually_converted(): void
    {
        $invoice = $this->settledInvoice();

        $this->convert(['from' => 'USD', 'to' => 'PKR', '--rate' => '278', '--force' => true]);

        foreach ($invoice->fresh()->items()->get() as $item) {
            $this->assertEqualsWithDelta(2780.00, (float) $item->unit_price, 0.001);
        }
    }

    /**
     * The regression with the worst consequence: paid_amount was converted while
     * the payment rows were not, so the next status recalculation rebuilt
     * paid_amount from the untouched payments and marked a settled invoice unpaid.
     */
    public function test_payments_are_converted_so_the_invoice_stays_settled(): void
    {
        $invoice = $this->settledInvoice();

        $this->convert(['from' => 'USD', 'to' => 'PKR', '--rate' => '278', '--force' => true]);

        $invoice->refresh();

        $payments = $invoice->payments()->get();

        $this->assertCount(1, $payments);
        $this->assertEqualsWithDelta(32526.00, (float) $payments->first()->amount, 0.001);
        $this->assertSame('PKR', $payments->first()->currency);

        $this->assertEqualsWithDelta(
            (float) $payments->sum('amount'),
            (float) $invoice->paid_amount,
            0.001,
            'paid_amount must equal the sum of the converted payments, or the next recalculation reverts it',
        );
    }

    /**
     * Belt and braces: run the real status recalculation after converting and
     * assert the invoice is still fully paid.
     */
    public function test_a_recalculated_status_still_reads_paid(): void
    {
        $invoice = $this->settledInvoice();

        $this->convert(['from' => 'USD', 'to' => 'PKR', '--rate' => '278', '--force' => true]);

        $invoice->refresh();

        app(InvoicePaymentService::class)->recalculateStatus($invoice->fresh());

        $this->assertSame(
            'paid',
            $invoice->fresh()->status,
            'the invoice must still be settled after conversion and recalculation',
        );
    }

    public function test_an_unpaid_invoice_converts_without_inventing_payments(): void
    {
        $invoice = $this->settledInvoice('USD', '0');

        $this->convert(['from' => 'USD', 'to' => 'PKR', '--rate' => '278', '--force' => true]);

        $invoice->refresh();

        $this->assertEqualsWithDelta(0, (float) $invoice->paid_amount, 0.001);
        $this->assertSame(0, $invoice->payments()->count());
    }

    public function test_the_same_source_and_target_is_refused(): void
    {
        $this->settledInvoice();

        $this->artisan('currency:convert', ['from' => 'USD', 'to' => 'USD', '--rate' => '1'])
            ->assertFailed();
    }

    public function test_a_non_positive_rate_is_refused(): void
    {
        $this->settledInvoice();

        $this->artisan('currency:convert', [
            'from' => 'USD', 'to' => 'PKR', '--rate' => '0', '--force' => true,
        ])->assertFailed();

        $this->assertSame('USD', SalesInvoice::first()->currency, 'nothing may be converted on a bad rate');
    }

    /**
     * The shared helper is the reason the header reconciles: the create/edit path
     * and the conversion path must price a line identically.
     */
    public function test_line_maths_is_shared_with_the_document_editor(): void
    {
        $helper = DocumentItems::lineTotals(10, 10.00, 10, 17);

        $this->assertSame(90.00, $helper['line_net']);
        $this->assertSame(15.30, $helper['line_tax']);

        // sync() writes line rows, so it needs a persisted document to hang them
        // off; an unsaved model would try to insert with a null invoice_id.
        $scratch = SalesInvoice::create([
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

        $viaSync = DocumentItems::sync($scratch, [
            [
                'description' => 'Widget',
                'qty' => '10',
                'unit_price' => '10.00',
                'discount_percent' => '10',
                'tax_percent' => '17',
            ],
        ]);

        $this->assertEqualsWithDelta($viaSync['subtotal'], $helper['line_net'], 0.001);
        $this->assertEqualsWithDelta($viaSync['tax'], $helper['line_tax'], 0.001);
    }
}
