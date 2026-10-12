<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Support\GeneralLedger;
use App\Services\Accounting\LedgerPosting;
use App\Services\Accounting\YearEndClose;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Retained earnings must be a real figure, not a balancing plug.
 *
 * `FinancialReportController::balanceSheet()` computed retained earnings as
 * `assets - liabilities - equity`. That forces the statement to balance by
 * arithmetic whatever the ledger contains: a transposition, a misposting or a
 * missing entry simply moved the retained line to hide it. The balance sheet
 * always agreed, which meant it never told anybody anything.
 *
 * A year-end close moves the period's revenue and expense into account 3100, so
 * the figure is the profit the business actually made. With that in place a
 * ledger that does not add up shows up as an unbalanced balance sheet rather than
 * being quietly absorbed.
 */
class YearEndCloseTest extends TestCase
{
    use SeedsDatabase;

    private function close(): YearEndClose
    {
        return new YearEndClose();
    }

    private function balanceFor(string $code): float
    {
        return Account::where('code', $code)->first()?->balance() ?? 0.0;
    }

    /**
     * A balanced ledger closes into retained earnings and empties the P&L.
     */
    public function test_a_year_closes_into_retained_earnings(): void
    {
        // Revenue 1000, expense 400 -> 600 profit.
        $this->postRevenueAndExpense();

        $this->assertNotSame(0.0, $this->balanceFor('4000'), 'the fixture did not post revenue');

        $entry = $this->close()->close('2026-12-31');

        $this->assertNotNull($entry, 'the year-end close produced no journal');
        $this->assertSame('posted', $entry->status);

        // Debit-positive: revenue carries a credit balance and is closed with a
        // debit, so the retained earnings credit is what the closing entry adds.
        $this->assertSame(600.0, round(-$this->balanceFor('3100'), 2), 'retained earnings does not carry the profit');

        // And the P&L accounts are flat afterwards.
        $this->assertSame(0.0, round($this->balanceFor('4000'), 2), 'revenue was not closed off');
        $this->assertSame(0.0, round($this->balanceFor('5000'), 2), 'expense was not closed off');
    }

    /**
     * The closing entry has to balance, like every other journal in the system.
     */
    public function test_the_closing_entry_balances(): void
    {
        $this->postRevenueAndExpense();

        $entry = $this->close()->close('2026-12-31');
        $this->assertNotNull($entry);

        $lines = $entry->items()->get();

        $this->assertSame(
            round((float) $lines->sum('debit'), 2),
            round((float) $lines->sum('credit'), 2),
            'the closing journal does not balance',
        );
    }

    /**
     * Closing the same year twice must not credit retained earnings again — there
     * would be nothing left in the P&L to justify it.
     */
    public function test_closing_the_same_year_twice_is_refused(): void
    {
        $this->postRevenueAndExpense();

        $first = $this->close()->close('2026-12-31');
        $this->assertNotNull($first);

        $second = $this->close()->close('2026-12-31');
        $this->assertNull($second, 'a second close credited retained earnings again');
    }

    /**
     * A year that made nothing needs no entry, rather than a zero-value one.
     */
    public function test_a_year_with_no_activity_closes_to_nothing(): void
    {
        $this->assertNull($this->close()->close('2026-12-31'));
    }

    /**
     * A close must not depend on accounts it does not use.
     *
     * `close()` originally gated on `LedgerPosting::isReady()`, which requires
     * receivables, payables, bank and the sales-returns account. A close touches
     * none of them, so on the live database — where 4300 had not been seeded — the
     * close refused a year carrying 12,700 of profit and returned null, which read
     * as "nothing to do" while the year was in fact left open.
     *
     * Removing the unrelated account must not stop the books being closed.
     */
    public function test_the_close_does_not_depend_on_unrelated_accounts(): void
    {
        $this->postRevenueAndExpense();

        // Everything LedgerPosting::isReady() insists on, except the one account a
        // close genuinely needs.
        Account::where('code', LedgerPosting::SALES_RETURNS)->delete();

        LedgerPosting::flushReadiness();
        $this->assertFalse(
            (new LedgerPosting())->isReady(),
            'fixture is invalid: the ledger gate is still reporting ready',
        );

        $entry = $this->close()->close('2026-12-31');

        $this->assertNotNull($entry, 'an unrelated missing account stopped the year being closed');

        // Debit-positive: the profit sits on the credit side of 3100, hence -600.
        $this->assertSame(600.0, round(-$this->balanceFor('3100'), 2), 'the profit did not reach retained earnings');
    }

    /**
     * A close that cannot run must say so, not report success.
     *
     * Returning null for "the account you need is missing" is indistinguishable
     * from "there was nothing to close", so the operator is told the books are
     * fine while no entry was posted. The one account a close truly needs is
     * checked by name, and the failure names it.
     */
    public function test_a_missing_retained_earnings_account_raises_rather_than_silently_returning_null(): void
    {
        $this->postRevenueAndExpense();

        Account::where('code', LedgerPosting::RETAINED_EARNINGS)->delete();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(LedgerPosting::RETAINED_EARNINGS);

        $this->close()->close('2026-12-31');
    }

    /**
     * A loss debits retained earnings. Crediting it would report the company as
     * owing itself money.
     */
    public function test_a_loss_reduces_retained_earnings(): void
    {
        $this->postRevenueAndExpense(profit: false);

        $this->close()->close('2026-12-31');

        // Debit-positive: a loss DEBITS 3100.
        $this->assertGreaterThan(0, $this->balanceFor('3100'), 'a loss was not debited to retained earnings');
    }

    /**
     * Post a small revenue and expense pair, optionally unprofitable.
     */
    private function postRevenueAndExpense(bool $profit = true): void
    {
        $revenue = $profit ? 1000.00 : 400.00;
        $expense = $profit ? 400.00 : 1000.00;

        $entry = JournalEntry::create([
            'number' => 'JV-FIXTURE-'.uniqid(),
            'entry_date' => '2026-06-30',
            'reference' => 'fixture:'.uniqid(),
            'description' => 'Fixture',
            // Draft, because `GeneralLedger::post()` refuses an entry that is
            // already posted -- the two steps are deliberately separate.
            'status' => 'draft',
        ]);

        $lines = [
            ['account_id' => Account::where('code', '4000')->value('id'), 'debit' => '0', 'credit' => (string) $revenue],
            ['account_id' => Account::where('code', '5000')->value('id'), 'debit' => (string) $expense, 'credit' => '0'],
        ];

        // Only add the cash leg when it is non-zero: GeneralLedger rejects a line
        // that carries neither a debit nor a credit, and an exactly-breakeven year
        // has nothing to put there.
        $difference = round($revenue - $expense, 2);

        if ($difference !== 0.0) {
            // Profit puts cash on the debit side; a loss is funded from the bank,
            // so cash is credited. Either way the fixture has to balance.
            $lines[] = $difference > 0
                ? [
                    'account_id' => Account::where('code', '1010')->value('id'),
                    'debit' => (string) $difference,
                    'credit' => '0',
                ]
                : [
                    'account_id' => Account::where('code', '1010')->value('id'),
                    'debit' => '0',
                    'credit' => (string) abs($difference),
                ];
        }

        GeneralLedger::replaceLines($entry, $lines);
        GeneralLedger::post($entry);
    }
}
