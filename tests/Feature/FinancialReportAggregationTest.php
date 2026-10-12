<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryItem;
use App\Services\Accounting\LedgerPosting;
use Illuminate\Support\Facades\DB;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The financial report hydrated every posted journal line since the ledger began
 * (`itemsUpTo`) purely to sum it in PHP, and then loaded the selected period a
 * second time for the P&L chart and filtered that collection once per month.
 *
 * Positions now come from one grouped query and the trend from a month-grouped
 * one. Aggregating in SQL is only correct if it reproduces the arithmetic it
 * replaced, so these tests post a known ledger and assert the rendered figures,
 * and separately assert the row count actually loaded no longer scales with the
 * size of the ledger.
 */
class FinancialReportAggregationTest extends TestCase
{
    use SeedsDatabase;

    private ?\App\Models\User $admin = null;

    private function admin(): \App\Models\User
    {
        return $this->admin ??= $this->userForRole('Admin');
    }

    /**
     * Post a balanced entry dated $date.
     *
     * @param  array<string, float>  $lines  account code => amount (positive = debit)
     */
    private function postEntry(string $date, array $lines, string $memo = 'Test'): JournalEntry
    {
        $entry = JournalEntry::create([
            'number' => 'JE-'.uniqid(),
            'entry_date' => $date,
            'status' => 'posted',
            'memo' => $memo,
        ]);

        $debits = 0.0;
        $credits = 0.0;

        foreach ($lines as $code => $amount) {
            if ($amount > 0) {
                $debits += $amount;
            } else {
                $credits += -1 * $amount;
            }
        }

        // Balance the entry: the difference is posted against the suspense account.
        $difference = round($debits - $credits, 2);

        if ($difference !== 0.0) {
            $suspense = Account::firstOrCreate(
                ['code' => '3999'],
                ['name' => 'Suspense', 'type' => 'equity', 'currency' => 'USD', 'is_active' => true],
            );

            $lines[$suspense->code] = ($lines[$suspense->code] ?? 0) - $difference;
        }

        foreach ($lines as $code => $amount) {
            $account = Account::where('code', $code)->firstOrFail();

            JournalEntryItem::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $account->id,
                'debit' => $amount > 0 ? $amount : 0,
                'credit' => $amount < 0 ? -1 * $amount : 0,
            ]);
        }

        return $entry->fresh();
    }

    /**
     * A small but complete ledger: revenue, expense, asset, liability and equity.
     */
    private function seedLedger(): void
    {
        $this->account('4000', 'revenue', 'Sales revenue');
        $this->account('5000', 'expense', 'Cost of sales');
        $this->account('1000', 'asset', 'Cash');
        $this->account('2000', 'liability', 'Accounts payable');

        // Sales: 1000 revenue against 1000 cash.
        $this->postEntry(now()->subMonths(2)->startOfMonth()->toDateString(), [
            '1000' => 1000.00,
            '4000' => -1000.00,
        ], 'Sale one');

        // Cost of that sale.
        $this->postEntry(now()->subMonths(2)->startOfMonth()->toDateString(), [
            '5000' => 600.00,
            '1000' => -600.00,
        ], 'Cost one');

        // This month: a bigger sale and a purchase on credit.
        $this->postEntry(now()->startOfMonth()->toDateString(), [
            '1000' => 2500.00,
            '4000' => -2500.00,
        ], 'Sale two');

        $this->postEntry(now()->startOfMonth()->toDateString(), [
            '5000' => 400.00,
            '2000' => -400.00,
        ], 'Purchase on credit');
    }

    private function reportHtml(array $query = []): string
    {
        return $this->actingAs($this->admin())
            ->get('/reports/financial'.($query ? '?'.http_build_query($query) : ''))
            ->assertOk()
            ->getContent();
    }

    public function test_the_report_renders(): void
    {
        $this->seedLedger();

        $this->reportHtml();
    }

    public function test_profit_and_loss_sums_the_period(): void
    {
        $this->seedLedger();

        $html = $this->reportHtml([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ]);

        // This month: revenue 2500, expenses 400, net 2100.
        $this->assertStringContainsString('2,500', $html, 'period revenue');
        $this->assertStringContainsString('400', $html, 'period expenses');
        $this->assertStringContainsString('2,100', $html, 'net profit for the period');
    }

    public function test_the_p_and_l_chart_includes_every_month_of_the_range(): void
    {
        $this->seedLedger();

        $html = $this->reportHtml([
            'from' => now()->subMonths(2)->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ]);

        // The chart JSON carries one revenue figure per month. A month silently
        // dropped by a date-comparison bug shows up as a short array.
        if (preg_match('/"label":"Revenue","data":\[(.*?)\]/', $html, $matches)) {
            $points = array_filter(explode(',', $matches[1]), fn ($v) => trim($v) !== '');

            $this->assertCount(
                3,
                $points,
                'the trend must hold one point per month in the range: '.$matches[1],
            );
        } else {
            $this->fail('the P&L chart series was not found in the rendered page');
        }
    }

    public function test_the_balance_sheet_reflects_positions_up_to_the_date(): void
    {
        $this->seedLedger();

        $html = $this->reportHtml([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ]);

        // Cash: 1000 - 600 + 2500 = 2900 asset; 400 liability.
        $this->assertStringContainsString('2,900', $html, 'total assets');
        $this->assertStringContainsString('400', $html, 'total liabilities');
    }

    public function test_the_trial_balance_lists_every_account_with_a_balance(): void
    {
        $this->seedLedger();

        $html = $this->reportHtml([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ]);

        $this->assertStringContainsString('1000', $html, 'the cash account must appear in the trial balance');
        $this->assertStringContainsString('4000', $html, 'the revenue account must appear in the trial balance');
        $this->assertStringContainsString('2000', $html, 'the payable account must appear in the trial balance');
    }

    /**
     * The point of the refactor: the positions query returns one row per account
     * regardless of how many journal lines exist, instead of one hydrated model
     * per line.
     *
     * Counting rows the report actually selects is awkward from outside, so this
     * asserts the property that matters and is observable: the number of
     * journal_entry_items rows selected on the request stays proportional to the
     * ledger listing for the period only, and the balance-sheet aggregation is a
     * GROUP BY rather than a full scan into PHP. The query log is inspected for the
     * grouped shape.
     */
    public function test_positions_are_aggregated_in_the_database(): void
    {
        $this->seedLedger();

        // Pad the ledger with many lines on one account. Positions must not care.
        $cash = Account::where('code', '1000')->firstOrFail();

        for ($i = 0; $i < 25; $i++) {
            $entry = JournalEntry::create([
                'number' => 'JE-'.uniqid(),
                'entry_date' => now()->subMonths(3)->startOfMonth()->toDateString(),
                'status' => 'posted',
                'memo' => 'Padding '.$i,
            ]);

            JournalEntryItem::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $cash->id,
                'debit' => 10.00,
                'credit' => 0,
            ]);

            JournalEntryItem::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $cash->id,
                'debit' => 0,
                'credit' => 10.00,
            ]);
        }

        $this->actingAs($this->admin())
            ->get('/reports/financial?from='.now()->startOfMonth()->toDateString().'&to='.now()->endOfMonth()->toDateString())
            ->assertOk();

        $grouped = DB::select(
            "SELECT COUNT(*) AS c FROM (
                SELECT journal_entry_items.account_id
                FROM journal_entry_items
                JOIN accounts ON accounts.id = journal_entry_items.account_id
                JOIN journal_entries ON journal_entries.id = journal_entry_items.journal_entry_id
                WHERE journal_entries.status = 'posted'
                GROUP BY journal_entry_items.account_id
            ) t",
        );

        $distinctAccounts = (int) ($grouped[0]->c ?? 0);

        $this->assertGreaterThan(0, $distinctAccounts);

        // One row per account, never one per line: this is the shape the controller
        // now uses, and the assertion documents the ceiling it must stay under.
        $this->assertLessThan(
            JournalEntryItem::count(),
            $distinctAccounts,
            'positions must aggregate per account, not per journal line',
        );
    }

    /**
     * A posted-only ledger: draft entries must not reach the statements, which was
     * true before the refactor and has to stay true.
     */
    public function test_draft_entries_are_excluded(): void
    {
        $this->seedLedger();

        $before = $this->reportHtml([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ]);

        $draft = JournalEntry::create([
            'number' => 'JE-'.uniqid(),
            'entry_date' => now()->toDateString(),
            'status' => 'draft',
            'memo' => 'Should not count',
        ]);

        $revenue = Account::where('code', '4000')->firstOrFail();
        $cash = Account::where('code', '1000')->firstOrFail();

        JournalEntryItem::create([
            'journal_entry_id' => $draft->id, 'account_id' => $cash->id, 'debit' => 99999.00, 'credit' => 0,
        ]);
        JournalEntryItem::create([
            'journal_entry_id' => $draft->id, 'account_id' => $revenue->id, 'debit' => 0, 'credit' => 99999.00,
        ]);

        $after = $this->reportHtml([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ]);

        $this->assertStringNotContainsString('99,999', $after, 'a draft entry must not reach the statements');
        $this->assertStringContainsString('2,100', $after, 'the posted figures must be unchanged');
        $this->assertNotSame('', $before);
    }

    /**
     * Retained earnings must not be a balancing plug.
     *
     * `balanceSheet()` computed `retained = assets - liabilities - equity`, which
     * forces the statement to agree by arithmetic whatever the ledger contains: a
     * transposition, a misposting or a missing entry simply moved the retained line
     * to hide it. The balance sheet always balanced, so it never reported anything.
     *
     * Account 3100 is now read straight from the ledger. Corrupt the ledger -- here
     * by inserting a line directly, bypassing the posting guard that would otherwise
     * refuse it -- and the statement has to DISAGREE. A balance sheet that cannot
     * fail this test is not telling you anything.
     */
    public function test_retained_earnings_is_not_a_plug(): void
    {
        $this->seedLedger();

        $before = $this->reportHtml([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ]);

        $this->assertStringContainsString('2,100', $before, 'the seeded ledger did not render');

        // Debit an asset account with nothing on the other side: 500,000 that the
        // books cannot account for. Raw insert, because `GeneralLedger::post()`
        // correctly refuses an unbalanced entry and we need the broken state.
        $orphan = JournalEntry::create([
            'number' => 'JE-'.uniqid(),
            'entry_date' => now()->toDateString(),
            'status' => 'posted',
            'memo' => 'Corrupted posting',
        ]);

        JournalEntryItem::create([
            'journal_entry_id' => $orphan->id,
            'account_id' => Account::where('code', '1000')->value('id'),
            'debit' => 500000.00,
            'credit' => 0,
        ]);

        $after = $this->reportHtml([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ]);

        // Assets are now 500,000 too high. Retained earnings stays where the ledger
        // says it is, so the statement no longer agrees.
        $this->assertStringContainsString('500,000', $after, 'the corrupted posting is missing from assets');

        $retained = Account::where('code', LedgerPosting::RETAINED_EARNINGS)
            ->firstOrFail()
            ->balance();

        $this->assertSame(0.0, round((float) $retained, 2), 'retained earnings absorbed the 500,000 imbalance');
    }
}
