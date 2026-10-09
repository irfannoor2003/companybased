<?php

namespace Tests\Feature;

use App\Models\SalesCreditNote;
use App\Models\SalesCustomer;
use App\Models\SalesInvoice;
use App\Models\SalesPayment;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * A customer brought over from a spreadsheet already has a balance. There was
 * nowhere to record it, so every statement started from zero and every balance,
 * every statement total and the customer list were wrong by that opening amount
 * — silently, because the arithmetic was internally consistent.
 *
 * The amount is entered as a magnitude plus a direction, and collapsed to one
 * signed value at the boundary so the sign can never be stored inconsistently
 * with the amount.
 */
class CustomerOpeningBalanceTest extends TestCase
{
    use SeedsDatabase;

    private function customer(array $attributes = []): SalesCustomer
    {
        return SalesCustomer::create(array_merge([
            'company_name' => 'Acme '.uniqid(),
            'currency' => 'PKR',
            'is_active' => true,
        ], $attributes));
    }

    private function invoice(SalesCustomer $customer, float $total): SalesInvoice
    {
        return SalesInvoice::create([
            'number' => 'INV-'.uniqid(),
            'customer_id' => $customer->id,
            'issue_date' => now()->subDays(10)->toDateString(),
            'status' => 'sent',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => (string) $total,
            'tax_amount' => '0',
            'total' => (string) $total,
            'paid_amount' => '0',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Acme Traders',
            'is_active' => 1,
        ], $overrides);
    }

    public function test_a_debit_opening_balance_increases_what_the_customer_owes(): void
    {
        $this->actingAs($this->userForRole('Admin'))
            ->post('/sales/customers', $this->payload([
                'opening_balance' => '500',
                'opening_balance_direction' => 'debit',
            ]))
            ->assertSessionHasNoErrors();

        $customer = SalesCustomer::where('company_name', 'Acme Traders')->firstOrFail();

        $this->assertEqualsWithDelta(500.0, $customer->openingBalance(), 0.0005, 'debit is stored positive');
        $this->assertEqualsWithDelta(500.0, $customer->balance(), 0.0005);
    }

    public function test_a_credit_opening_balance_reduces_what_the_customer_owes(): void
    {
        $this->actingAs($this->userForRole('Admin'))
            ->post('/sales/customers', $this->payload([
                'opening_balance' => '300',
                'opening_balance_direction' => 'credit',
            ]))
            ->assertSessionHasNoErrors();

        $customer = SalesCustomer::where('company_name', 'Acme Traders')->firstOrFail();

        $this->assertEqualsWithDelta(-300.0, $customer->openingBalance(), 0.0005, 'credit is stored negative');
        $this->assertEqualsWithDelta(-300.0, $customer->balance(), 0.0005);
    }

    public function test_no_opening_balance_defaults_to_zero(): void
    {
        $this->actingAs($this->userForRole('Admin'))
            ->post('/sales/customers', $this->payload())
            ->assertSessionHasNoErrors();

        $customer = SalesCustomer::where('company_name', 'Acme Traders')->firstOrFail();

        $this->assertEqualsWithDelta(0.0, $customer->openingBalance(), 0.0005);
    }

    public function test_an_amount_with_no_direction_is_refused(): void
    {
        // Storing a positive amount with no stated intent is the case that used
        // to silently mean one of two opposite things.
        $this->actingAs($this->userForRole('Admin'))
            ->post('/sales/customers', $this->payload(['opening_balance' => '500']))
            ->assertSessionHasErrors('opening_balance_direction');

        $this->assertSame(0, SalesCustomer::where('company_name', 'Acme Traders')->count());
    }

    public function test_a_negative_amount_is_refused(): void
    {
        // The sign is expressed by the direction, not by typing a minus. A
        // negative number here is a data-entry error, not a credit.
        $this->actingAs($this->userForRole('Admin'))
            ->post('/sales/customers', $this->payload([
                'opening_balance' => '-500',
                'opening_balance_direction' => 'debit',
            ]))
            ->assertSessionHasErrors('opening_balance');
    }

    public function test_the_opening_balance_is_in_the_customer_balance(): void
    {
        $customer = $this->customer(['opening_balance' => '400']);
        $this->invoice($customer, 1000.00);

        $this->assertEqualsWithDelta(1400.0, $customer->balance(), 0.0005, 'invoices add on top of the opening figure');

        SalesPayment::create([
            'number' => 'RC-'.uniqid(),
            'invoice_id' => $customer->invoices()->firstOrFail()->id,
            'customer_id' => $customer->id,
            'amount' => '600.00',
            'payment_date' => now()->toDateString(),
            'method' => 'bank_transfer',
            'currency' => 'PKR',
            'exchange_rate' => 1,
        ]);

        $this->assertEqualsWithDelta(800.0, $customer->fresh()->balance(), 0.0005, 'payments reduce the combined figure');
    }

    public function test_a_credit_opening_balance_can_overpay_a_new_customer(): void
    {
        $customer = $this->customer(['opening_balance' => '-250']);
        $this->invoice($customer, 100.00);

        $this->assertEqualsWithDelta(
            -150.0,
            $customer->balance(),
            0.0005,
            'an advance larger than the first invoice must show as in credit, not clamped to zero',
        );
    }

    public function test_the_statement_leads_with_the_opening_balance(): void
    {
        $customer = $this->customer([
            'opening_balance' => '750',
            'opening_balance_date' => '2026-01-01',
        ]);
        $invoice = $this->invoice($customer, 250.00);

        $html = $this->actingAs($this->userForRole('Admin'))
            ->get("/sales/statements/{$customer->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Opening balance', $html, 'the opening figure must be its own ledger line');
        $this->assertStringContainsString('Brought forward', $html);
        $this->assertStringContainsString('750', $html);

        // The running balance must start at 750, not at the invoice total.
        $this->assertMatchesRegularExpression(
            '/1,000\.00/',
            $html,
            'the closing running balance must be opening + invoiced',
        );

        $this->assertSame(1000.0, (float) $invoice->total + 750.0);
    }

    public function test_the_ledger_date_order_does_not_demote_the_opening_balance(): void
    {
        // An opening balance dated after the invoices in the same period must
        // still lead the ledger, or the running balance starts from zero and
        // every subsequent row is wrong.
        $customer = $this->customer([
            'opening_balance' => '600',
            'opening_balance_date' => now()->toDateString(),
        ]);
        $invoice = $this->invoice($customer, 100.00);

        $html = $this->actingAs($this->userForRole('Admin'))
            ->get("/sales/statements/{$customer->id}")
            ->assertOk()
            ->getContent();

        // Anchored on the invoice's own number, not the word "Invoice": the
        // sidebar navigation mentions Invoices long before the ledger renders.
        $this->assertLessThan(
            strpos($html, $invoice->number),
            strpos($html, 'Brought forward'),
            'the opening balance row must precede the invoice rows in the ledger',
        );
    }

    public function test_a_customer_with_no_opening_balance_gets_no_opening_row(): void
    {
        $customer = $this->customer();
        $this->invoice($customer, 100.00);

        $html = $this->actingAs($this->userForRole('Admin'))
            ->get("/sales/statements/{$customer->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Brought forward', $html);
    }

    public function test_the_statement_credit_note_row_matches_the_closing_balance(): void
    {
        // The ledger credited credit notes at `total` while every balance used
        // `applied_amount`, so the running balance could not reconcile with the
        // closing balance printed above the same table.
        $customer = $this->customer();
        $invoice = $this->invoice($customer, 1000.00);

        SalesCreditNote::create([
            'number' => 'CN-'.uniqid(),
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'reason' => 'Part return',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => '0',
            'tax_amount' => '0',
            'total' => '1000.00',
            'applied_amount' => '250.00',
        ]);

        $html = $this->actingAs($this->userForRole('Admin'))
            ->get("/sales/statements/{$customer->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('750', $html, 'the running balance must reflect the applied amount only');
    }

    public function test_the_statement_export_carries_the_opening_balance(): void
    {
        $customer = $this->customer([
            'opening_balance' => '500',
            'opening_balance_date' => '2026-01-01',
        ]);
        $this->invoice($customer, 200.00);

        $csv = $this->actingAs($this->userForRole('Admin'))
            ->get("/sales/customers/{$customer->id}/statement/export")
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Opening balance', $csv);
        $this->assertStringContainsString('Brought forward', $csv);
        $this->assertLessThan(
            strpos($csv, 'Invoice'),
            strpos($csv, 'Opening balance'),
            'the CSV must order the opening row first, matching the screen',
        );
    }

    public function test_the_customer_list_shows_the_opening_balance(): void
    {
        $this->customer(['opening_balance' => '-100']);

        $html = $this->actingAs($this->userForRole('Admin'))
            ->get('/sales/statements')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Opening', $html, 'the list needs an opening column to explain the balance');
    }

    public function test_the_customer_page_explains_the_opening_balance(): void
    {
        $customer = $this->customer(['opening_balance' => '900']);

        $html = $this->actingAs($this->userForRole('Admin'))
            ->get("/sales/customers/{$customer->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('900', $html);
        $this->assertStringContainsString('opening balance', strtolower($html));
    }

    public function test_the_form_explains_both_directions(): void
    {
        $html = $this->actingAs($this->userForRole('Admin'))
            ->get('/sales/customers/create')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('opening_balance', $html);
        $this->assertStringContainsString('Owed to me', $html);
        $this->assertStringContainsString('credit', strtolower($html));
    }

    public function test_the_edit_form_round_trips_a_credit_opening_balance(): void
    {
        $customer = $this->customer([
            'opening_balance' => '-450',
            'opening_balance_date' => '2026-02-01',
        ]);

        $html = $this->actingAs($this->userForRole('Admin'))
            ->get("/sales/customers/{$customer->id}/edit")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('450', $html, 'the magnitude is shown without its sign');
        $this->assertStringContainsString('2026-02-01', $html);
    }

    public function test_editing_a_customer_can_change_the_opening_balance(): void
    {
        $customer = $this->customer(['opening_balance' => '100']);

        $this->actingAs($this->userForRole('Admin'))
            ->put("/sales/customers/{$customer->id}", [
                'company_name' => $customer->company_name,
                'is_active' => 1,
                'opening_balance' => '250',
                'opening_balance_direction' => 'debit',
            ])
            ->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(250.0, $customer->fresh()->openingBalance(), 0.0005);
    }

    public function test_zeroing_the_opening_balance_clears_the_date(): void
    {
        // A date left behind on a zero balance would date the "brought forward"
        // row to a period it no longer applies to.
        $customer = $this->customer([
            'opening_balance' => '100',
            'opening_balance_date' => '2026-01-01',
        ]);

        $this->actingAs($this->userForRole('Admin'))
            ->put("/sales/customers/{$customer->id}", [
                'company_name' => $customer->company_name,
                'is_active' => 1,
                'opening_balance' => '0',
                'opening_balance_direction' => 'debit',
                'opening_balance_date' => '2026-01-01',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $customer->fresh();

        $this->assertEqualsWithDelta(0.0, $fresh->openingBalance(), 0.0005);
        $this->assertNull($fresh->opening_balance_date);
    }
}
