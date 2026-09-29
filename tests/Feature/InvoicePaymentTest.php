<?php

namespace Tests\Feature;

use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\SalesPayment;
use App\Models\SupplierPayment;
use App\Services\InvoicePaymentService;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Recording a payment is the most financially consequential write in the
 * application, and the rules behind it (lock, ownership, currency, overpayment,
 * status transition) were duplicated verbatim across the sales and supplier
 * payment controllers. They now live in InvoicePaymentService, and these tests
 * pin the behaviour both controllers depend on.
 */
class InvoicePaymentTest extends TestCase
{
    use SeedsDatabase;

    private function invoice(float $total = 1000.00, string $currency = 'PKR', string $status = 'sent'): SalesInvoice
    {
        return SalesInvoice::create([
            'number' => 'INV-'.uniqid(),
            'customer_id' => $this->customer()->id,
            'issue_date' => now()->toDateString(),
            'status' => $status,
            'currency' => $currency,
            'exchange_rate' => 1,
            'subtotal' => (string) $total,
            'tax_amount' => '0',
            'total' => (string) $total,
            'paid_amount' => '0',
        ]);
    }

    private function payload(SalesInvoice $invoice, array $overrides = []): array
    {
        return array_merge([
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'amount' => '100.00',
            'payment_date' => now()->toDateString(),
            'method' => 'bank_transfer',
        ], $overrides);
    }

    private function purchaseInvoice(float $total = 400.00): PurchaseInvoice
    {
        return PurchaseInvoice::create([
            'number' => 'PINV-'.uniqid(),
            'supplier_id' => $this->supplier('Payment Vendor')->id,
            'issue_date' => now()->toDateString(),
            'status' => 'sent',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => (string) $total,
            'tax_amount' => '0',
            'total' => (string) $total,
            'paid_amount' => '0',
        ]);
    }

    public function test_a_payment_advances_the_paid_amount_and_status(): void
    {
        $invoice = $this->invoice(1000.00);

        $this->actingAs($this->userForRole('Admin'))
            ->post('/sales/payment-in', $this->payload($invoice))
            ->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(100.0, (float) $invoice->fresh()->paid_amount, 0.0005);
        $this->assertSame('partially_paid', $invoice->fresh()->status);
    }

    public function test_paying_in_full_marks_the_invoice_paid(): void
    {
        $invoice = $this->invoice(250.00);

        $this->actingAs($this->userForRole('Admin'))
            ->post('/sales/payment-in', $this->payload($invoice, ['amount' => '250.00']))
            ->assertSessionHasNoErrors();

        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_a_payment_is_refused_when_it_exceeds_the_balance(): void
    {
        $invoice = $this->invoice(100.00);

        $this->actingAs($this->userForRole('Admin'))
            ->post('/sales/payment-in', $this->payload($invoice, ['amount' => '500.00']))
            ->assertSessionHas('toasts');

        $this->assertEqualsWithDelta(0.0, (float) $invoice->fresh()->paid_amount, 0.0005, 'no payment may be recorded');
        $this->assertSame(0, SalesPayment::count());
    }

    public function test_a_payment_must_be_made_by_the_invoice_owner(): void
    {
        $invoice = $this->invoice();
        $otherCustomer = $this->customer('Someone Else');

        $this->actingAs($this->userForRole('Admin'))
            ->post('/sales/payment-in', $this->payload($invoice, ['customer_id' => $otherCustomer->id]))
            ->assertSessionHas('toasts');

        $this->assertSame(0, SalesPayment::count(), 'a payment may not be attributed to another customer');
    }

    public function test_a_payment_currency_must_match_the_invoice_currency(): void
    {
        $invoice = $this->invoice(1000.00, 'PKR');

        $this->actingAs($this->userForRole('Admin'))
            ->post('/sales/payment-in', $this->payload($invoice, ['currency' => 'USD']))
            ->assertSessionHas('toasts');

        $this->assertSame(0, SalesPayment::count());
    }

    public function test_cumulative_payments_cannot_pass_the_invoice_total(): void
    {
        $invoice = $this->invoice(100.00);
        $user = $this->userForRole('Admin');

        $this->actingAs($user)->post('/sales/payment-in', $this->payload($invoice, ['amount' => '60.00']));

        // 60 is already recorded, so only 40 remains and a second 60 must fail.
        $this->actingAs($user)
            ->post('/sales/payment-in', $this->payload($invoice, ['amount' => '60.00']))
            ->assertSessionHas('toasts');

        $this->assertEqualsWithDelta(60.0, (float) $invoice->fresh()->paid_amount, 0.0005, 'the second payment must be refused');
        $this->assertSame(1, SalesPayment::count());
    }

    public function test_a_status_transition_is_recorded(): void
    {
        $invoice = $this->invoice(100.00);

        $this->actingAs($this->userForRole('Admin'))
            ->post('/sales/payment-in', $this->payload($invoice, ['amount' => '100.00']));

        $this->assertDatabaseHas('sales_status_events', [
            'trackable_type' => SalesInvoice::class,
            'trackable_id' => $invoice->id,
            'from_status' => 'sent',
            'to_status' => 'paid',
        ]);
    }

    public function test_a_cancelled_invoice_cannot_be_dragged_back_into_a_live_state(): void
    {
        $invoice = $this->invoice(100.00, 'PKR', 'cancelled');
        $service = app(InvoicePaymentService::class);

        $service->applyPaidAmount($invoice, 100.0);
        $service->recalculateStatus($invoice);

        $this->assertSame('cancelled', $invoice->fresh()->status);
    }

    public function test_remaining_balance_ignores_the_payment_being_edited(): void
    {
        $invoice = $this->invoice(100.00);
        $user = $this->userForRole('Admin');

        $this->actingAs($user)->post('/sales/payment-in', $this->payload($invoice, ['amount' => '100.00']));

        $payment = SalesPayment::firstOrFail();
        $service = app(InvoicePaymentService::class);

        $this->assertEqualsWithDelta(
            0.0,
            $service->remainingBalance($invoice->fresh()),
            0.0005,
            'nothing remains after a full payment',
        );

        $this->assertEqualsWithDelta(
            100.0,
            $service->remainingBalance($invoice->fresh(), $payment->id),
            0.0005,
            'excluding the payment under edit restores the full total',
        );
    }

    public function test_a_payment_cannot_be_reassigned_to_another_invoice(): void
    {
        $invoice = $this->invoice(1000.00);
        $other = $this->invoice(1000.00);
        $user = $this->userForRole('Admin');

        $this->actingAs($user)->post('/sales/payment-in', $this->payload($invoice));

        $payment = SalesPayment::firstOrFail();

        $this->actingAs($user)
            ->put("/sales/payment-in/{$payment->id}", $this->payload($other))
            ->assertSessionHas('toasts');

        $this->assertSame($invoice->id, $payment->fresh()->invoice_id, 'the link must not move');
    }

    public function test_the_supplier_side_uses_the_same_rules(): void
    {
        $invoice = $this->purchaseInvoice(400.00);

        $this->actingAs($this->userForRole('Admin'))
            ->post('/suppliers/supplier-payments', [
                'invoice_id' => $invoice->id,
                'supplier_id' => $invoice->supplier_id,
                'amount' => '400.00',
                'payment_date' => now()->toDateString(),
                'method' => 'bank_transfer',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, SupplierPayment::count());
    }

    public function test_the_supplier_side_also_refuses_overpayment(): void
    {
        $invoice = $this->purchaseInvoice(100.00);

        $this->actingAs($this->userForRole('Admin'))
            ->post('/suppliers/supplier-payments', [
                'invoice_id' => $invoice->id,
                'supplier_id' => $invoice->supplier_id,
                'amount' => '900.00',
                'payment_date' => now()->toDateString(),
                'method' => 'bank_transfer',
            ])
            ->assertSessionHas('toasts');

        $this->assertSame(0, SupplierPayment::count());
    }
}
