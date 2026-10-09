<?php

namespace Tests\Feature;

use App\Models\SalesCreditNote;
use App\Models\SalesCustomer;
use App\Models\SalesInvoice;
use App\Models\User;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * A credit note had no financial effect anywhere in the system.
 *
 * `applied_amount` was in $fillable and read by the customer balance, the
 * statements and the supplier ledger — but no code path ever wrote it, so it sat
 * at zero forever. Issuing a 500.00 note against a 2,000.00 invoice left the
 * balance at 2,000.00 and printed a 0.00 credit line: a document that looked
 * like it returned money and did not.
 *
 * These tests pin the whole chain, because half of it working is worse than none
 * of it working — the note has to reduce the customer balance AND the invoice it
 * was raised against, or the same value is counted once and owed once.
 */
class CreditNoteSettlementTest extends TestCase
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
            'company_name' => 'Nexos '.uniqid(),
            'currency' => 'PKR',
            'is_active' => true,
            'opening_balance' => 0,
        ]);
    }

    private function invoice(SalesCustomer $customer, float $total): SalesInvoice
    {
        return SalesInvoice::create([
            'number' => 'INV-'.uniqid(),
            'customer_id' => $customer->id,
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

    /**
     * @return array<string, mixed>
     */
    private function notePayload(SalesCustomer $customer, ?SalesInvoice $invoice, float $amount): array
    {
        return [
            'customer_id' => $customer->id,
            'invoice_id' => $invoice?->id,
            'issue_date' => now()->toDateString(),
            'status' => 'issued',
            'items' => [[
                'description' => 'Returned goods',
                'qty' => '1',
                'unit_price' => (string) $amount,
                'discount_percent' => '0',
                'tax_percent' => '0',
            ]],
        ];
    }

    public function test_a_credit_note_reduces_what_the_customer_owes(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer, 2000);

        $this->assertEqualsWithDelta(2000.0, $customer->fresh()->balance(), 0.0005);

        $this->actingAs($this->admin())
            ->post('/sales/credit-notes', $this->notePayload($customer, $invoice, 500))
            ->assertSessionHasNoErrors();

        $note = SalesCreditNote::firstOrFail();

        $this->assertEqualsWithDelta(
            500.0,
            (float) $note->applied_amount,
            0.0005,
            'the note has to record what it actually settled — this is the figure the ledger reads',
        );

        $this->assertEqualsWithDelta(
            1500.0,
            $customer->fresh()->balance(),
            0.0005,
            'and the customer owes the remainder',
        );
    }

    public function test_a_credit_note_also_settles_the_invoice_it_was_raised_against(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer, 2000);

        $this->actingAs($this->admin())
            ->post('/sales/credit-notes', $this->notePayload($customer, $invoice, 500))
            ->assertSessionHasNoErrors();

        // Without this the note reduces the customer ledger while the invoice it
        // credits still reads as fully owed — the customer can be chased for money
        // they have already been credited.
        $this->assertEqualsWithDelta(
            500.0,
            (float) $invoice->fresh()->credit_note_applied,
            0.0005,
        );
        $this->assertEqualsWithDelta(1500.0, $invoice->fresh()->balance(), 0.0005);
    }

    public function test_a_note_larger_than_the_invoice_credits_the_customer_in_full(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer, 300);

        $this->actingAs($this->admin())
            ->post('/sales/credit-notes', $this->notePayload($customer, $invoice, 900))
            ->assertSessionHasNoErrors();

        $note = SalesCreditNote::firstOrFail();

        // The customer is owed all 900 back — capping what the note credits at
        // the invoice's balance would lose the other 600 entirely.
        $this->assertEqualsWithDelta(
            900.0,
            (float) $note->applied_amount,
            0.0005,
            'the ledger credits the whole note',
        );

        // Only 300 of it can settle this bill; applying 900 would leave the
        // invoice over-credited.
        $this->assertEqualsWithDelta(
            300.0,
            (float) $note->credit_note_applied,
            0.0005,
            'the invoice absorbs only what it still owes',
        );
        $this->assertEqualsWithDelta(
            600.0,
            (float) $note->remaining(),
            0.0005,
            'and the excess is credit the customer still holds',
        );
        $this->assertEqualsWithDelta(0.0, $invoice->fresh()->balance(), 0.0005);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertEqualsWithDelta(
            -600.0,
            $customer->fresh()->balance(),
            0.0005,
        );
    }

    public function test_a_deleted_note_stops_reducing_the_balance(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer, 2000);

        $this->actingAs($this->admin())
            ->post('/sales/credit-notes', $this->notePayload($customer, $invoice, 500))
            ->assertSessionHasNoErrors();

        $note = SalesCreditNote::firstOrFail();

        $this->actingAs($this->admin())
            ->delete("/sales/credit-notes/{$note->id}")
            ->assertSessionHas('toasts');

        $this->assertEqualsWithDelta(
            2000.0,
            $customer->fresh()->balance(),
            0.0005,
            'a deleted note must not keep crediting the customer',
        );
        $this->assertEqualsWithDelta(
            0.0,
            (float) $invoice->fresh()->credit_note_applied,
            0.0005,
            'nor the invoice',
        );
    }

    public function test_two_notes_against_one_invoice_do_not_double_credit_it(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer, 1000);

        $this->actingAs($this->admin())
            ->post('/sales/credit-notes', $this->notePayload($customer, $invoice, 600))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin())
            ->post('/sales/credit-notes', $this->notePayload($customer, $invoice, 600))
            ->assertSessionHasNoErrors();

        // The second note can only settle the 400 still outstanding.
        $absorbed = SalesCreditNote::orderBy('id')
            ->pluck('credit_note_applied')
            ->map(fn ($v) => (float) $v)
            ->all();

        $this->assertEqualsWithDelta(600.0, $absorbed[0], 0.0005);
        $this->assertEqualsWithDelta(400.0, $absorbed[1], 0.0005);
        $this->assertEqualsWithDelta(1000.0, (float) $invoice->fresh()->credit_note_applied, 0.0005);
        $this->assertEqualsWithDelta(0.0, $invoice->fresh()->balance(), 0.0005);
    }

    public function test_cash_cannot_be_collected_for_value_a_note_already_returned(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer, 2000);

        $this->actingAs($this->admin())
            ->post('/sales/credit-notes', $this->notePayload($customer, $invoice, 500))
            ->assertSessionHasNoErrors();

        // The remaining balance is 1,500, so a 1,800 receipt must be refused
        // rather than taking the customer to -300 and reporting the invoice paid.
        $this->actingAs($this->admin())
            ->post('/sales/payment-in', [
                'invoice_id' => $invoice->id,
                'customer_id' => $customer->id,
                'amount' => '1800.00',
                'payment_date' => now()->toDateString(),
                'method' => 'bank_transfer',
            ])
            ->assertSessionHas('toasts');

        $this->assertEqualsWithDelta(0.0, (float) $invoice->fresh()->paid_amount, 0.0005);
    }
}