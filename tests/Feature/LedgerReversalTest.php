<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\SalesPayment;
use App\Models\SupplierPayment;
use App\Services\Accounting\LedgerPosting;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Posting was one-way. Deleting a receipt left "cash in / receivable down" in the
 * ledger for money that no longer existed, and amending a receipt left the old
 * amount posted forever. Both meant the trial balance drifted permanently and the
 * statements stopped agreeing with the documents they came from.
 *
 * Corrections void the original entry and raise a new one, so both the mistake and
 * its fix stay visible on the audit trail.
 */
class LedgerReversalTest extends TestCase
{
    use SeedsDatabase;

    private ?\App\Models\User $admin = null;

    /** userForRole() inserts a row per call; reuse one actor per test. */
    private function admin(): \App\Models\User
    {
        return $this->admin ??= $this->userForRole('Admin');
    }

    private function ledger(): LedgerPosting
    {
        return new LedgerPosting();
    }

    private function code(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    private function invoice(float $total = 1000.00): SalesInvoice
    {
        return SalesInvoice::create([
            'number' => 'INV-'.uniqid(),
            'customer_id' => $this->customer()->id,
            'issue_date' => now()->subDay()->toDateString(),
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => (string) $total,
            'tax_amount' => '0.00',
            'total' => (string) $total,
            'paid_amount' => '0',
            'credit_applied' => '0',
            'credit_note_applied' => '0',
        ]);
    }

    private function receipt(SalesInvoice $invoice, float $amount): SalesPayment
    {
        return SalesPayment::create([
            'number' => 'PAY-'.uniqid(),
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'amount' => (string) $amount,
            'payment_date' => now()->toDateString(),
            'method' => 'cash',
            'currency' => 'USD',
            'exchange_rate' => 1,
        ]);
    }

    // ── reverse() ────────────────────────────────────────────────────────────

    public function test_reversing_voids_the_entry_rather_than_deleting_it(): void
    {
        $invoice = $this->invoice();
        $entry = $this->ledger()->salesInvoiceIssued($invoice);

        $this->assertTrue($this->ledger()->reverse($invoice, 'Mistake'));

        $entry->refresh();

        $this->assertSame('void', $entry->status, 'the original entry must survive as void');
        $this->assertTrue($entry->exists());
        $this->assertStringContainsString('voided: Mistake', $entry->description);
    }

    public function test_a_voided_entry_stops_counting_toward_the_balance(): void
    {
        $invoice = $this->invoice(1000.00);
        $this->ledger()->salesInvoiceIssued($invoice);

        $this->assertSame(1000.00, (float) $this->code(LedgerPosting::ACCOUNTS_RECEIVABLE)->balance());

        $this->ledger()->reverse($invoice);

        $this->assertSame(
            0.00,
            (float) $this->code(LedgerPosting::ACCOUNTS_RECEIVABLE)->balance(),
            'a voided entry must not leave the receivable overstated',
        );
    }

    public function test_reversing_twice_is_a_no_op(): void
    {
        $invoice = $this->invoice();
        $this->ledger()->salesInvoiceIssued($invoice);

        $this->assertTrue($this->ledger()->reverse($invoice));
        $this->assertFalse($this->ledger()->reverse($invoice), 'already void');
        $this->assertFalse($this->ledger()->reverse($invoice), 'still void');
    }

    public function test_reversing_an_unposted_document_is_a_no_op(): void
    {
        $this->assertFalse($this->ledger()->reverse($this->invoice()));
    }

    // ── repost() ─────────────────────────────────────────────────────────────

    public function test_reposting_voids_the_old_entry_and_raises_a_new_one(): void
    {
        $invoice = $this->invoice(1000.00);

        $original = $this->ledger()->salesInvoiceIssued($invoice);
        $this->ledger()->repost(
            $invoice,
            fn ($ledger) => $ledger->salesInvoiceIssued($invoice),
            'Re-issued',
        );

        $this->assertSame('void', $original->fresh()->status);

        $replacement = $this->ledger()->journalFor($invoice);

        $this->assertNotNull($replacement);
        $this->assertNotSame($original->id, $replacement->id, 'a fresh entry must be raised');
        $this->assertSame('posted', $replacement->status);

        $this->assertSame(1000.00, (float) $this->code(LedgerPosting::ACCOUNTS_RECEIVABLE)->balance());
    }

    public function test_an_amended_amount_reaches_the_ledger(): void
    {
        $invoice = $this->invoice(1000.00);
        $payment = $this->receipt($invoice, 400.00);

        $this->ledger()->paymentReceived($payment);

        $this->assertSame(400.00, (float) $this->code(LedgerPosting::CASH)->balance());

        // The receipt is corrected to 250.00 and re-posted.
        $payment->update(['amount' => '250.00']);

        $this->ledger()->repost(
            $payment->fresh(),
            fn ($ledger) => $ledger->paymentReceived($payment->fresh()),
            'Receipt amended',
        );

        $this->assertSame(
            250.00,
            (float) $this->code(LedgerPosting::CASH)->balance(),
            'the ledger must carry the amended amount, not the original',
        );
    }

    // ── through the HTTP layer ───────────────────────────────────────────────

public function test_deleting_a_receipt_voids_its_journal(): void
    {
        $invoice = $this->invoice(1000.00);

        $this->actingAs($this->admin())
            ->post('/sales/payment-in', [
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'amount' => '600.00',
                'payment_date' => now()->toDateString(),
                'method' => 'cash',
            ]);

        // The POST is what creates the receipt. Seeding one here as well would
        // make firstOrFail() return the unposted fixture rather than the real one.
        $stored = SalesPayment::firstOrFail();

        $this->assertTrue($this->ledger()->hasPosted($stored));

        $this->actingAs($this->admin())
            ->delete("/sales/payment-in/{$stored->id}")
            ->assertRedirect();

        $entries = JournalEntry::where('reference', SalesPayment::class.':'.$stored->id)->get();

        $this->assertNotEmpty($entries);
        $this->assertSame(
            ['void'],
            $entries->pluck('status')->unique()->values()->all(),
            'the receipt journal must be void, never left posted after the money went back out',
        );

        $this->assertSame(0.00, (float) $this->code(LedgerPosting::CASH)->balance());
        $this->assertSame(0.00, (float) $this->code(LedgerPosting::ACCOUNTS_RECEIVABLE)->balance());
    }

    public function test_deleting_a_supplier_payment_voids_its_journal(): void
    {
        $supplier = $this->supplier();

        $bill = PurchaseInvoice::create([
            'number' => 'BILL-'.uniqid(),
            'supplier_id' => $supplier->id,
            'issue_date' => now()->subDay()->toDateString(),
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '800.00',
            'tax_amount' => '0.00',
            'total' => '800.00',
            'paid_amount' => '0',
        ]);

        $this->actingAs($this->admin())
            ->post('/suppliers/supplier-payments', [
                'invoice_id' => $bill->id,
                'supplier_id' => $supplier->id,
                'amount' => '800.00',
                'payment_date' => now()->toDateString(),
                'method' => 'cash',
            ]);

        $payment = SupplierPayment::firstOrFail();
        $this->assertTrue($this->ledger()->hasPosted($payment));

        $this->actingAs($this->admin())
            ->delete("/suppliers/supplier-payments/{$payment->id}")
            ->assertRedirect();

        $entries = JournalEntry::where('reference', SupplierPayment::class.':'.$payment->id)->get();

        $this->assertSame(
            ['void'],
            $entries->pluck('status')->unique()->values()->all(),
        );
    }

    public function test_amending_a_receipt_reposts_it(): void
    {
        $invoice = $this->invoice(1000.00);

        $this->actingAs($this->admin())
            ->post('/sales/payment-in', [
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'amount' => '400.00',
                'payment_date' => now()->toDateString(),
                'method' => 'cash',
            ]);

        $payment = SalesPayment::firstOrFail();
        $before = JournalEntry::where('reference', SalesPayment::class.':'.$payment->id)->count();

        $this->actingAs($this->admin())
            ->put("/sales/payment-in/{$payment->id}", [
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'amount' => '275.50',
                'payment_date' => now()->toDateString(),
                'method' => 'cash',
            ])
            ->assertSessionHas('toasts');

        $entries = JournalEntry::where('reference', SalesPayment::class.':'.$payment->id)->get();

        $this->assertGreaterThan(
            $before,
            $entries->count(),
            'amending a receipt must void the old journal and raise a new one',
        );

        $this->assertContains('void', $entries->pluck('status')->all());
        $this->assertContains('posted', $entries->pluck('status')->all());

        $this->assertSame(
            275.50,
            (float) $this->code(LedgerPosting::CASH)->balance(),
            'the ledger must show the amended amount',
        );
    }

    /**
     * The history has to be reconstructable: voided entries stay on file.
     */
    public function test_the_voided_entry_is_still_readable_for_audit(): void
    {
        $invoice = $this->invoice(1000.00);
        $entry = $this->ledger()->salesInvoiceIssued($invoice);

        $this->ledger()->reverse($invoice, 'Entered against the wrong customer');

        $archived = JournalEntry::with('items')->findOrFail($entry->id);

        $this->assertNotNull($archived, 'a voided journal must not be deleted');
        $this->assertCount(2, $archived->items);
        $this->assertSame('void', $archived->status);
    }
}