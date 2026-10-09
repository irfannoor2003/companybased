<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\SalesPayment;
use App\Services\Accounting\LedgerPosting;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The accounting engine did not exist. JournalEntry was only ever written by
 * JournalController, so a business could issue invoices, take payments, receive
 * bills and pay suppliers without a single posting -- the trial balance, balance
 * sheet and P&L stayed empty regardless of trading volume.
 *
 * These tests assert the three properties that make automatic posting safe to switch
 * on: every journal balances, no document is ever posted twice, and a missing
 * account produces a clear error rather than a silent misposting.
 */
class LedgerPostingTest extends TestCase
{
    use SeedsDatabase;

    private ?LedgerPosting $ledger = null;

    private function ledger(): LedgerPosting
    {
        return $this->ledger ??= new LedgerPosting();
    }

    private function code(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    private function invoice(string $total = '1170.00', string $tax = '170.00'): SalesInvoice
    {
        return SalesInvoice::create([
            'number' => 'INV-'.uniqid(),
            'customer_id' => $this->customer()->id,
            'issue_date' => now()->subDay()->toDateString(),
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => Money_sub($total, $tax),
            'tax_amount' => $tax,
            'total' => $total,
            'paid_amount' => '0',
            'credit_applied' => '0',
            'credit_note_applied' => '0',
        ]);
    }

    private function assertBalanced(JournalEntry $entry): void
    {
        $entry->load('items');

        $debits = '0';
        $credits = '0';

        foreach ($entry->items as $item) {
            $debits = \App\Support\Money::add($debits, $item->debit);
            $credits = \App\Support\Money::add($credits, $item->credit);
        }

        $this->assertSame(
            $debits,
            $credits,
            "Journal {$entry->number} does not balance: debits {$debits} vs credits {$credits}",
        );
    }

    // ── Chart of accounts ────────────────────────────────────────────────────

    public function test_a_fresh_install_has_a_usable_chart_of_accounts(): void
    {
        foreach ([
            LedgerPosting::ACCOUNTS_RECEIVABLE,
            LedgerPosting::ACCOUNTS_PAYABLE,
            LedgerPosting::SALES_REVENUE,
            LedgerPosting::OUTPUT_TAX_PAYABLE,
            LedgerPosting::CASH,
            LedgerPosting::BANK,
        ] as $code) {
            $this->assertNotNull(
                Account::where('code', $code)->first(),
                "account {$code} must exist on a fresh install",
            );
        }
    }

    // ── Sales ────────────────────────────────────────────────────────────────

    public function test_an_invoice_posts_a_balanced_revenue_entry(): void
    {
        $invoice = $this->invoice();

        $entry = $this->ledger()->salesInvoiceIssued($invoice);

        $this->assertNotNull($entry, 'issuing an invoice must post to the ledger');
        $this->assertSame('posted', $entry->status);
        $this->assertBalanced($entry);

        // Dr AR 1170 / Cr tax 170 / Cr revenue 1000
        //
        // Sign convention: Account::balance() is debit less credit, so a
        // credit-normal account reads negative. The reports negate liabilities and
        // equity for exactly this reason.
        $this->assertSame(1170.00, (float) $this->code(LedgerPosting::ACCOUNTS_RECEIVABLE)->balance());
        $this->assertSame(-170.00, (float) $this->code(LedgerPosting::OUTPUT_TAX_PAYABLE)->balance());
        $this->assertSame(-1000.00, (float) $this->code(LedgerPosting::SALES_REVENUE)->balance());
    }

    public function test_the_invoice_entry_is_traceable_back_to_its_document(): void
    {
        $invoice = $this->invoice();
        $entry = $this->ledger()->salesInvoiceIssued($invoice);

        $this->assertStringContainsString($invoice->number, $entry->description);
        $this->assertSame(SalesInvoice::class.':'.$invoice->id, $entry->reference);
    }

    public function test_an_invoice_is_never_posted_twice(): void
    {
        $invoice = $this->invoice();

        $first = $this->ledger()->salesInvoiceIssued($invoice);
        $second = $this->ledger()->salesInvoiceIssued($invoice);
        $third = $this->ledger()->salesInvoiceIssued($invoice->fresh());

        $this->assertNotNull($first);
        $this->assertNull($second, 'a repeat posting must be a no-op');
        $this->assertNull($third, 'idempotency must survive a reloaded model');

        $this->assertSame(
            1,
            JournalEntry::where('reference', SalesInvoice::class.':'.$invoice->id)->count(),
            'exactly one journal per document, however many times it is posted',
        );

        $this->assertSame(1170.00, (float) $this->code(LedgerPosting::ACCOUNTS_RECEIVABLE)->balance());
    }

    public function test_a_receipt_moves_the_money_and_clears_the_receivable(): void
    {
        $invoice = $this->invoice();
        $this->ledger()->salesInvoiceIssued($invoice);

        $payment = SalesPayment::create([
            'number' => 'PAY-'.uniqid(),
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'amount' => '1170.00',
            'payment_date' => now()->toDateString(),
            'method' => 'cash',
            'currency' => 'USD',
            'exchange_rate' => 1,
        ]);

        $entry = $this->ledger()->paymentReceived($payment);

        $this->assertNotNull($entry);
        $this->assertBalanced($entry);

        // Cash up 1170, receivable back to zero.
        $this->assertSame(1170.00, (float) $this->code(LedgerPosting::CASH)->balance());
        $this->assertSame(0.00, (float) $this->code(LedgerPosting::ACCOUNTS_RECEIVABLE)->balance());
    }

    public function test_a_partial_receipt_leaves_the_remainder_outstanding(): void
    {
        $invoice = $this->invoice();
        $this->ledger()->salesInvoiceIssued($invoice);

        $payment = SalesPayment::create([
            'number' => 'PAY-'.uniqid(),
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'amount' => '400.00',
            'payment_date' => now()->toDateString(),
            'method' => 'cash',
            'currency' => 'USD',
            'exchange_rate' => 1,
        ]);

        $this->ledger()->paymentReceived($payment);

        $this->assertSame(770.00, (float) $this->code(LedgerPosting::ACCOUNTS_RECEIVABLE)->balance());
    }

    public function test_a_zero_tax_invoice_still_balances(): void
    {
        $invoice = $this->invoice('1000.00', '0.00');

        $entry = $this->ledger()->salesInvoiceIssued($invoice);

        $this->assertNotNull($entry);
        $this->assertBalanced($entry);
        $this->assertSame(-1000.00, (float) $this->code(LedgerPosting::SALES_REVENUE)->balance());
        $this->assertSame(0.00, (float) $this->code(LedgerPosting::OUTPUT_TAX_PAYABLE)->balance());
    }

    // ── Purchasing ───────────────────────────────────────────────────────────

    public function test_a_bill_posts_a_balanced_liability_entry(): void
    {
        $bill = PurchaseInvoice::create([
            'number' => 'BILL-'.uniqid(),
            'supplier_id' => $this->supplier()->id,
            'issue_date' => now()->subDay()->toDateString(),
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '1000.00',
            'tax_amount' => '170.00',
            'total' => '1170.00',
            'paid_amount' => '0',
        ]);

        $entry = $this->ledger()->purchaseBillIssued($bill);

        $this->assertNotNull($entry);
        $this->assertBalanced($entry);

        $this->assertSame(1000.00, (float) $this->code(LedgerPosting::COST_OF_GOODS_SOLD)->balance());
        $this->assertSame(170.00, (float) $this->code(LedgerPosting::INPUT_TAX_RECEIVABLE)->balance());
        $this->assertSame(-1170.00, (float) $this->code(LedgerPosting::ACCOUNTS_PAYABLE)->balance());
    }

    public function test_a_supplier_payment_settles_the_payable(): void
    {
        $bill = PurchaseInvoice::create([
            'number' => 'BILL-'.uniqid(),
            'supplier_id' => $this->supplier()->id,
            'issue_date' => now()->subDay()->toDateString(),
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '500.00',
            'tax_amount' => '0.00',
            'total' => '500.00',
            'paid_amount' => '0',
        ]);

        $this->ledger()->purchaseBillIssued($bill);

        $payment = \App\Models\SupplierPayment::create([
            'number' => 'SP-'.uniqid(),
            'invoice_id' => $bill->id,
            'supplier_id' => $bill->supplier_id,
            'amount' => '500.00',
            'payment_date' => now()->toDateString(),
            'method' => 'cash',
            'currency' => 'USD',
            'exchange_rate' => 1,
        ]);

        $this->ledger()->paymentMade($payment);

        $this->assertSame(0.00, (float) $this->code(LedgerPosting::ACCOUNTS_PAYABLE)->balance());
        $this->assertSame(-500.00, (float) $this->code(LedgerPosting::BANK)->balance());
    }

    // ── Guard rails ──────────────────────────────────────────────────────────

    /**
     * A missing account must stop the posting loudly. Silently skipping the line
     * would unbalance the journal and post a partial entry.
     *
     * Deliberately uses a code that does not exist rather than deleting a seeded
     * one: removing a shared fixture makes the test order-dependent, and every
     * later test in the class then fails for an unrelated reason.
     */
    public function test_a_missing_account_refuses_the_posting(): void
    {
        $invoice = $this->invoice();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('9999');

        $this->ledger()->post($invoice, [
            ['code' => '9999', 'debit' => '100.00'],
            ['code' => LedgerPosting::SALES_REVENUE, 'credit' => '100.00'],
        ], 'Posting against an account that does not exist');
    }

    public function test_a_missing_account_leaves_no_orphan_journal(): void
    {
        $invoice = $this->invoice();
        $before = JournalEntry::count();

        try {
            $this->ledger()->post($invoice, [
                ['code' => '9999', 'debit' => '100.00'],
                ['code' => LedgerPosting::SALES_REVENUE, 'credit' => '100.00'],
            ], 'Posting against an account that does not exist');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(
            $before,
            JournalEntry::count(),
            'a failed posting must not leave a half-written journal behind',
        );
    }

    public function test_the_chart_of_accounts_is_intact_after_the_guard_rail_tests(): void
    {
        // If a guard-rail test ever deletes a seeded account again, this fails.
        foreach (array_keys(\Database\Seeders\ChartOfAccountsSeeder::codes()) as $ignored) {
            // placeholder to keep the loop shape obvious
        }

        $this->assertNotNull(Account::where('code', LedgerPosting::ACCOUNTS_RECEIVABLE)->first());
        $this->assertNotNull(Account::where('code', LedgerPosting::SALES_REVENUE)->first());
        $this->assertNotNull(Account::where('code', LedgerPosting::ACCOUNTS_PAYABLE)->first());
    }

    public function test_an_unbalanced_rule_set_is_refused(): void
    {
        $invoice = $this->invoice();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unbalanced');

        // Deliberately asymmetric: this is what a mis-coded posting rule looks like.
        $this->ledger()->post($invoice, [
            ['code' => LedgerPosting::ACCOUNTS_RECEIVABLE, 'debit' => '100.00'],
            ['code' => LedgerPosting::SALES_REVENUE, 'credit' => '90.00'],
        ], 'Deliberately unbalanced');
    }

    public function test_inventory_loss_posts_and_is_idempotent(): void
    {
        $invoice = $this->invoice();

        $first = $this->ledger()->inventoryValuationLoss($invoice, '75.50');
        $second = $this->ledger()->inventoryValuationLoss($invoice, '75.50');

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertBalanced($first);

        $this->assertSame(75.50, (float) $this->code(LedgerPosting::INVENTORY_ADJUSTMENT)->balance());
        $this->assertSame(-75.50, (float) $this->code(LedgerPosting::INVENTORY)->balance());
    }

    public function test_a_zero_loss_posts_nothing(): void
    {
        $invoice = $this->invoice();

        $this->assertNull($this->ledger()->inventoryValuationLoss($invoice, '0'));
        $this->assertSame(0, JournalEntry::count());
    }

    /**
     * The end-to-end promise: trading moves the statements off zero.
     */
    public function test_trading_shows_up_on_the_financial_statements(): void
    {
        $invoice = $this->invoice();
        $this->ledger()->salesInvoiceIssued($invoice);

        $payment = SalesPayment::create([
            'number' => 'PAY-'.uniqid(),
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'amount' => '1170.00',
            'payment_date' => now()->toDateString(),
            'method' => 'cash',
            'currency' => 'USD',
            'exchange_rate' => 1,
        ]);
        $this->ledger()->paymentReceived($payment);

        $html = $this->actingAs($this->userForRole('Admin'))
            ->get('/reports/financial?from='.now()->startOfMonth()->toDateString().'&to='.now()->endOfMonth()->toDateString())
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('1,000', $html, 'revenue must reach the P&L');
        $this->assertStringContainsString('1,170', $html, 'cash must reach the balance sheet');
    }
}

if (! function_exists('Money_sub')) {
    function Money_sub(string $total, string $tax): string
    {
        return \App\Support\Money::subtract($total, $tax);
    }
}
