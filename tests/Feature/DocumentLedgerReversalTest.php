<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\TaxRate;
use App\Services\Accounting\LedgerPosting;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Reversals for the documents themselves, not just the payments against them.
 *
 * A payment reversal left the invoice's own journal untouched, so deleting or
 * amending an invoice still stranded "receivable / revenue" on the books for a
 * document that had gone or changed. Same class of defect one level up.
 */
class DocumentLedgerReversalTest extends TestCase
{
    use SeedsDatabase;

    private ?\App\Models\User $admin = null;

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

    private function invoice(float $total = 1000.00, float $tax = 0.0): SalesInvoice
    {
        return SalesInvoice::create([
            'number' => 'INV-'.uniqid(),
            'customer_id' => $this->customer()->id,
            'issue_date' => now()->subDay()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => (string) ($total - $tax),
            'tax_amount' => (string) $tax,
            'total' => (string) $total,
            'paid_amount' => '0',
            'credit_applied' => '0',
            'credit_note_applied' => '0',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(SalesInvoice $invoice, float $total, float $tax = 0.0): array
    {
        return [
            'customer_id' => $invoice->customer_id,
            'issue_date' => now()->subDay()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft',
            'currency' => 'USD',
'items' => [[
                'description' => 'Consulting',
                'qty' => '1',
                'unit_price' => (string) ($total - $tax),
                'discount_percent' => '0',
                'tax_percent' => $tax > 0 ? '17' : '0',
            ]],
        ];
    }

    /**
     * The tax engine rejects a line that charges tax with no configured rate behind
     * it, so a taxed invoice now needs a real rate on file.
     *
     * This is intended product behaviour, not a tax-engine bug: posting 17% of tax
     * to the ledger with nothing defining which rate it was makes the return
     * irreproducible. The test predates that control, so it configures the rate it
     * has been charging all along.
     */
    private function ensureSalesTaxRate(): void
    {
        TaxRate::firstOrCreate(
            ['code' => 'REVERSAL-17'],
            [
                'name' => 'Standard VAT',
                'rate' => '17.0000',
                'applies_to' => 'sales',
                'effective_from' => now()->subYear()->startOfYear()->toDateString(),
                'is_active' => true,
                'account_id' => Account::where('code', '2200')->value('id'),
            ],
        );
    }

private function postInvoice(SalesInvoice $invoice, float $total, float $tax = 0.0): void
    {
        if ($tax > 0) {
            $this->ensureSalesTaxRate();
        }

        $this->actingAs($this->admin())
            ->post('/sales/invoices', $this->invoicePayload($invoice, $total, $tax))
            ->assertSessionHasNoErrors();
    }

    // ── deletion ─────────────────────────────────────────────────────────────

    public function test_deleting_an_invoice_voids_its_journal(): void
    {
        $this->postInvoice($invoice = $this->invoice(1000.00), 1000.00);

        $created = SalesInvoice::where('customer_id', $invoice->customer_id)->latest('id')->firstOrFail();

        $this->assertTrue($this->ledger()->hasPosted($created));
        $this->assertSame(1000.00, (float) $this->code(LedgerPosting::ACCOUNTS_RECEIVABLE)->balance());

        $this->actingAs($this->admin())
            ->delete("/sales/invoices/{$created->id}")
            ->assertRedirect();

        $entries = JournalEntry::where('reference', SalesInvoice::class.':'.$created->id)->get();

        $this->assertNotEmpty($entries);
        $this->assertSame(
            ['void'],
            $entries->pluck('status')->unique()->values()->all(),
            'deleting an invoice must void its journal',
        );

        $this->assertSame(
            0.00,
            (float) $this->code(LedgerPosting::ACCOUNTS_RECEIVABLE)->balance(),
            'the receivable must not survive the invoice that created it',
        );
    }

    public function test_deleting_a_bill_voids_its_journal(): void
    {
        $supplier = $this->supplier();

        $this->actingAs($this->admin())
            ->post('/suppliers/purchase-invoices', [
                'supplier_id' => $supplier->id,
                'issue_date' => now()->toDateString(),
                'status' => 'draft',
                'currency' => 'USD',
                'items' => [[
                    'description' => 'Goods',
                    'qty' => '1',
                    'unit_price' => '800.00',
                    'discount_percent' => '0',
                    'tax_percent' => '0',
                ]],
            ])
            ->assertSessionHasNoErrors();

        $bill = PurchaseInvoice::where('supplier_id', $supplier->id)->latest('id')->firstOrFail();

        $this->assertTrue($this->ledger()->hasPosted($bill));

        $this->actingAs($this->admin())
            ->delete("/suppliers/purchase-invoices/{$bill->id}")
            ->assertRedirect();

        $entries = JournalEntry::where('reference', PurchaseInvoice::class.':'.$bill->id)->get();

        $this->assertSame(['void'], $entries->pluck('status')->unique()->values()->all());
        $this->assertSame(0.00, (float) $this->code(LedgerPosting::ACCOUNTS_PAYABLE)->balance());
    }

    // ── amendment ────────────────────────────────────────────────────────────

    public function test_amending_an_invoice_reposts_its_journal(): void
    {
        $this->postInvoice($invoice = $this->invoice(1000.00), 1000.00);

        $created = SalesInvoice::where('customer_id', $invoice->customer_id)->latest('id')->firstOrFail();
        $original = $this->ledger()->journalFor($created);

        $this->assertSame(1000.00, (float) $this->code(LedgerPosting::ACCOUNTS_RECEIVABLE)->balance());

        // The invoice is retyped at 1500.00.
        $this->actingAs($this->admin())
            ->put("/sales/invoices/{$created->id}", $this->invoicePayload($created, 1500.00))
            ->assertSessionHas('toasts');

        $this->assertSame(
            'void',
            $original->fresh()->status,
            'the journal raised for the old total must be void',
        );

        $this->assertSame(
            1500.00,
            (float) $this->code(LedgerPosting::ACCOUNTS_RECEIVABLE)->balance(),
            'the ledger must carry the amended total, not the original',
        );
    }

    public function test_amending_an_invoice_with_tax_moves_both_revenue_and_tax(): void
    {
        $this->postInvoice($invoice = $this->invoice(1170.00, 170.00), 1170.00, 170.00);

        $created = SalesInvoice::where('customer_id', $invoice->customer_id)->latest('id')->firstOrFail();

        $this->assertSame(-1000.00, (float) $this->code(LedgerPosting::SALES_REVENUE)->balance());
        $this->assertSame(-170.00, (float) $this->code(LedgerPosting::OUTPUT_TAX_PAYABLE)->balance());

        $this->actingAs($this->admin())
            ->put("/sales/invoices/{$created->id}", $this->invoicePayload($created, 2340.00, 340.00))
            ->assertSessionHas('toasts');

        $this->assertSame(-2000.00, (float) $this->code(LedgerPosting::SALES_REVENUE)->balance());
        $this->assertSame(-340.00, (float) $this->code(LedgerPosting::OUTPUT_TAX_PAYABLE)->balance());
        $this->assertSame(2340.00, (float) $this->code(LedgerPosting::ACCOUNTS_RECEIVABLE)->balance());
    }

    /**
     * The voided entries must remain on file, or the ledger cannot be audited.
     */
    public function test_amending_an_invoice_keeps_both_entries_for_audit(): void
    {
        $this->postInvoice($invoice = $this->invoice(1000.00), 1000.00);

        $created = SalesInvoice::where('customer_id', $invoice->customer_id)->latest('id')->firstOrFail();
        $originalId = $this->ledger()->journalFor($created)->id;

        $this->actingAs($this->admin())
            ->put("/sales/invoices/{$created->id}", $this->invoicePayload($created, 1500.00))
            ->assertSessionHas('toasts');

        $entries = JournalEntry::where('reference', SalesInvoice::class.':'.$created->id)->get();

        $this->assertSame(2, $entries->count(), 'the original must survive alongside its replacement');

        $this->assertNotNull(JournalEntry::with('items')->find($originalId));

        $statuses = $entries->pluck('status')->all();
        $this->assertContains('void', $statuses);
        $this->assertContains('posted', $statuses);
    }

    /**
     * Only one entry may be live at a time, or revenue is recognised twice.
     */
    public function test_only_one_journal_per_document_is_live(): void
    {
        $this->postInvoice($invoice = $this->invoice(1000.00), 1000.00);

        $created = SalesInvoice::where('customer_id', $invoice->customer_id)->latest('id')->firstOrFail();

        foreach ([1500.00, 2000.00, 2500.00] as $amount) {
            $this->actingAs($this->admin())
                ->put("/sales/invoices/{$created->id}", $this->invoicePayload($created, $amount))
                ->assertSessionHas('toasts');
        }

        $live = JournalEntry::where('reference', SalesInvoice::class.':'.$created->id)
            ->where('status', 'posted')
            ->count();

        $this->assertSame(
            1,
            $live,
            'repeated amendments must not leave several live journals recognising the same sale',
        );

        $this->assertSame(2500.00, (float) $this->code(LedgerPosting::ACCOUNTS_RECEIVABLE)->balance());
    }
}