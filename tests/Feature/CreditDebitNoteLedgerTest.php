<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\DebitNote;
use App\Models\JournalEntry;
use App\Models\PurchaseInvoice;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\SalesCustomer;
use App\Services\Accounting\LedgerPosting;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Credit and debit notes must reach the general ledger.
 *
 * Both document types moved the party's *sub-ledger* balance — the customer went
 * down by the credited amount, the supplier by the debited one — and neither
 * touched the general ledger at all. Revenue, output tax, the receivable, expense
 * and input tax therefore all stayed exactly where the original document left
 * them.
 *
 * The visible symptom is a balance sheet that disagrees with the sales and
 * purchase registers: issue one credit note and the books show the sale as though
 * it had never been credited back.
 *
 * These tests assert the postings exist and are balanced, because the suite
 * previously passed 1,247 tests with no assertion of this kind anywhere in it.
 */
class CreditDebitNoteLedgerTest extends TestCase
{
    use SeedsDatabase;

    private function ledger(): LedgerPosting
    {
        return new LedgerPosting();
    }

    private function customer(): SalesCustomer
    {
        return SalesCustomer::create([
            'company_name' => 'Credit Note Co '.uniqid(),
            'currency' => 'USD',
            'is_active' => true,
            'opening_balance' => 0,
        ]);
    }

    private function supplier(): Supplier
    {
        return Supplier::create([
            'company_name' => 'Debit Note Co '.uniqid(),
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    /**
     * A credit note of 117.00 carrying 17.00 of tax.
     */
    private function creditNote(float $net = 100.00, float $tax = 17.00): SalesCreditNote
    {
        $note = SalesCreditNote::create([
            'number' => 'CN-'.uniqid(),
            'customer_id' => $this->customer()->id,
            'issue_date' => now()->subDay()->toDateString(),
            'reason' => 'Goods returned',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => (string) $net,
            'tax_amount' => (string) $tax,
            'total' => (string) ($net + $tax),
        ]);

        $note->items()->create([
            'product_id' => null,
            'description' => 'Returned goods',
            'qty' => 1,
            'unit_price' => (string) $net,
            'discount_percent' => 0,
            'tax_percent' => 0,
            'line_total' => (string) $net,
        ]);

        return $note->fresh();
    }

    private function debitNote(float $net = 100.00, float $tax = 17.00): DebitNote
    {
        $note = DebitNote::create([
            'number' => 'DBN-'.uniqid(),
            'supplier_id' => $this->supplier()->id,
            'issue_date' => now()->subDay()->toDateString(),
            'reason' => 'Goods returned to supplier',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => (string) $net,
            'tax_amount' => (string) $tax,
            'total' => (string) ($net + $tax),
        ]);

        $note->items()->create([
            'product_id' => null,
            'description' => 'Returned goods',
            'qty' => 1,
            'unit_price' => (string) $net,
            'discount_percent' => 0,
            'tax_percent' => 0,
            'line_total' => (string) $net,
        ]);

        return $note->fresh();
    }

/**
     * Net posted balance of an account, debit less credit.
     *
     * This application is DEBIT-POSITIVE throughout: `Account::balance()` is
     * `debits - credits` for every account type. A credit-normal liability like
     * Output Tax Payable therefore shows positive when money is owed to the
     * authority, and the sign is not re-flipped per account type. The deltas
     * below follow that convention -- a credit note DEBITS 2200, so its balance
     * moves by -17 relative to where a sale left it.
     */
    private function balanceFor(string $code): float
    {
        return Account::where('code', $code)->first()?->balance() ?? 0.0;
    }

    /**
     * The whole point: a credit note posts, and it posts balanced.
     */
    public function test_a_credit_note_reaches_the_general_ledger(): void
    {
        $note = $this->creditNote(100.00, 17.00);

        $journal = $this->ledger()->creditNoteIssued($note);

        $this->assertNotNull($journal, 'the credit note did not post a journal entry');
        $this->assertSame('posted', $journal->status);

        $lines = $journal->items()->get();
        $debit = round((float) $lines->sum('debit'), 2);
        $credit = round((float) $lines->sum('credit'), 2);

        $this->assertSame($debit, $credit, 'the credit note journal does not balance');
        $this->assertSame(117.00, $debit);
    }

    /**
     * The three legs, because "it posted something" is not enough: a credit note
     * that hit the wrong accounts would still balance.
     */
    public function test_a_credit_note_debits_returns_and_output_tax_and_credits_the_receivable(): void
    {
        $note = $this->creditNote(100.00, 17.00);

        $journal = $this->ledger()->creditNoteIssued($note);
        $this->assertNotNull($journal);

        $byCode = $journal->items()->get()->keyBy(fn ($i) => $i->account?->code);

        $this->assertSame(100.00, round((float) $byCode['4300']->debit, 2), 'net should debit Sales Returns');
        $this->assertSame(17.00, round((float) $byCode['2200']->debit, 2), 'tax should debit Output Tax Payable');
        $this->assertSame(117.00, round((float) $byCode['1100']->credit, 2), 'gross should credit Accounts Receivable');
    }

    public function test_a_debit_note_reaches_the_general_ledger(): void
    {
        $note = $this->debitNote(100.00, 17.00);

        $journal = $this->ledger()->debitNoteIssued($note);

        $this->assertNotNull($journal, 'the debit note did not post a journal entry');

        $lines = $journal->items()->get();
        $debit = round((float) $lines->sum('debit'), 2);
        $credit = round((float) $lines->sum('credit'), 2);

        $this->assertSame($debit, $credit, 'the debit note journal does not balance');
        $this->assertSame(117.00, $debit);
    }

    /**
     * Input tax has to be credited back.
     *
     * Leaving it claimed is an overclaim against the authority: the business would
     * be asking for money back on goods it no longer holds.
     */
    public function test_a_debit_note_credits_the_input_tax_back(): void
    {
        $note = $this->debitNote(100.00, 17.00);

        $journal = $this->ledger()->debitNoteIssued($note);
        $this->assertNotNull($journal);

        $byCode = $journal->items()->get()->keyBy(fn ($i) => $i->account?->code);

        $this->assertSame(117.00, round((float) $byCode['2000']->debit, 2), 'gross should debit Accounts Payable');
        $this->assertSame(100.00, round((float) $byCode['5000']->credit, 2), 'net should credit the expense');
        $this->assertSame(17.00, round((float) $byCode['1310']->credit, 2), 'input tax should be credited back');
    }

    /**
     * Posting twice must not double it.
     *
     * `post()` returns null when a document already has a journal at that stage,
     * so the second call is a no-op rather than a second entry. Re-saving a
     * document is not a second sale.
     */
    public function test_posting_a_credit_note_twice_does_not_double_it(): void
    {
        $note = $this->creditNote();

        $ledger = $this->ledger();

        $first = $ledger->creditNoteIssued($note);
        $this->assertNotNull($first);

        $second = $ledger->creditNoteIssued($note->fresh());
        $this->assertNull($second, 'the second call raised a second journal');

        // One entry per lifecycle stage, ever. The reference is "<class>:<id>".
        $this->assertSame(
            1,
            JournalEntry::query()
                ->where('reference', SalesCreditNote::class.':'.$note->id)
                ->count(),
            'more than one journal exists for the credit note',
        );
    }

    /**
     * The end-to-end claim, stated as a DELTA rather than an absolute balance.
     *
     * Absolute balances are wrong to assert on here: the chart of accounts is
     * seeded per test, but anything posted by an earlier test in the same class
     * still moves the account, so an absolute figure makes the assertion depend on
     * execution order. What must hold is that *this* note moved the account by
     * exactly its own figures, which is the actual claim.
     */
    public function test_a_credit_note_moves_the_tax_and_receivable_accounts(): void
    {
        $taxBefore = $this->balanceFor('2200');
        $receivableBefore = $this->balanceFor('1100');
        $returnsBefore = $this->balanceFor('4300');

        $this->ledger()->creditNoteIssued($this->creditNote(100.00, 17.00));

        // Debit-positive convention (see balanceFor): balance is debits - credits
        // for EVERY account type, including credit-normal liabilities.
        //
        // The credit note DEBITS Output Tax Payable (to give the collected tax back)
        // and DEBITS nothing on the receivable side beyond crediting it. So on a
        // debit-positive 2200 the balance moves UP by 17 -- the opposite of what a
        // "liability going down" intuition suggests. That sign convention is exactly
        // why these are asserted as measured deltas rather than absolute balances.
        $this->assertSame(
            round($taxBefore + 17.00, 2),
            $this->balanceFor('2200'),
            'output tax was not credited back',
        );

        // The credit note CREDITS the receivable, so debit-positive 1100 falls.
        $this->assertSame(
            round($receivableBefore - 117.00, 2),
            $this->balanceFor('1100'),
            'the receivable was not reduced',
        );

        // Sales Returns 4300 is debited, so debit-positive it rises.
        $this->assertSame(
            round($returnsBefore + 100.00, 2),
            $this->balanceFor('4300'),
            'the return was not recorded on Sales Returns',
        );
    }

    /**
     * The same claim on the purchase side: input tax must not stay claimed.
     */
    public function test_a_debit_note_reduces_the_payable_and_the_input_tax(): void
    {
        $payableBefore = $this->balanceFor('2000');
        $inputTaxBefore = $this->balanceFor('1310');

        $this->ledger()->debitNoteIssued($this->debitNote(100.00, 17.00));

        // Debit-positive convention. The debit note DEBITS Accounts Payable, so its
        // debit-positive balance RISES by the gross even though the amount actually
        // owed falls -- that sign flip is the convention, not an error.
        $this->assertSame(
            round($payableBefore + 117.00, 2),
            $this->balanceFor('2000'),
            'the payable was not reduced',
        );

        // Input Tax Receivable is credited back, so debit-positive falls: the claim
        // against the authority goes away with the goods.
        $this->assertSame(
            round($inputTaxBefore - 17.00, 2),
            $this->balanceFor('1310'),
            'input tax is still being claimed on returned goods',
        );
    }

    /**
     * Reversing a credit note must leave revenue, tax and the receivable back
     * exactly where they were before the note existed.
     *
     * Asserted on balances rather than on the journal's status, because a reversal
     * that posted its own error would still change a status field.
     */
    public function test_reversing_a_credit_note_cancels_its_effect(): void
    {
        $note = $this->creditNote(100.00, 17.00);

        $taxBefore = $this->balanceFor('2200');
        $receivableBefore = $this->balanceFor('1100');

        $ledger = $this->ledger();
        $ledger->creditNoteIssued($note);

        $this->assertNotSame($taxBefore, $this->balanceFor('2200'), 'the note did not post at all');

        $this->assertTrue($ledger->reverse($note->fresh(), 'Deleted'));

        $this->assertSame(
            round($taxBefore, 2),
            $this->balanceFor('2200'),
            'output tax was not restored by the reversal',
        );

        $this->assertSame(
            round($receivableBefore, 2),
            $this->balanceFor('1100'),
            'the receivable was not restored by the reversal',
        );
    }}
