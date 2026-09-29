<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Support\GeneralLedger;
use Database\Seeders\ChartOfAccountsSeeder;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The financial statements — trial balance, balance sheet, profit & loss — all
 * aggregate from `accounts`. The chart of accounts is seeded as structural
 * configuration (not demo data) so a fresh install can report on anything at
 * all; before this, `accounts` was empty and every accounting report rendered
 * blank.
 */
class ChartOfAccountsTest extends TestCase
{
    use SeedsDatabase;

    public function test_the_default_chart_of_accounts_is_seeded(): void
    {
        $this->assertGreaterThan(0, Account::count(), 'a fresh install must have a chart of accounts');

        foreach (['asset', 'liability', 'equity', 'revenue', 'expense'] as $type) {
            $this->assertGreaterThan(
                0,
                Account::where('type', $type)->count(),
                "the chart must contain at least one {$type} account",
            );
        }
    }

    public function test_seeded_accounts_are_active_and_usable(): void
    {
        foreach (Account::all() as $account) {
            $this->assertTrue($account->is_active, "account {$account->code} must be active");
            $this->assertContains($account->type, Account::typeOptions());
        }
    }

    public function test_the_cash_and_revenue_accounts_required_by_the_ledgers_exist(): void
    {
        $this->assertNotNull(Account::where('code', '1000')->first(), 'cash account');
        $this->assertNotNull(Account::where('code', '4000')->first(), 'sales revenue account');
        $this->assertNotNull(Account::where('code', '5000')->first(), 'cost of goods sold account');
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $before = Account::count();

        $this->seed(ChartOfAccountsSeeder::class);

        $this->assertSame($before, Account::count(), 're-seeding must not duplicate accounts');
    }

    public function test_a_balanced_journal_can_be_posted_against_the_seeded_chart(): void
    {
        $cash = Account::where('code', '1000')->firstOrFail();
        $revenue = Account::where('code', '4000')->firstOrFail();

        $entry = JournalEntry::create([
            'number' => 'JE-TEST-1',
            'entry_date' => now()->toDateString(),
            'description' => 'Cash sale',
            'status' => 'draft',
            'created_by' => $this->userForRole('Accountant')->id,
        ]);

        GeneralLedger::replaceLines($entry, [
            ['account_id' => $cash->id, 'debit' => '100.00', 'credit' => '0.00', 'memo' => 'Cash received'],
            ['account_id' => $revenue->id, 'debit' => '0.00', 'credit' => '100.00', 'memo' => 'Sale'],
        ]);

        GeneralLedger::post($entry->fresh());

        $this->assertSame('posted', $entry->fresh()->status);
        $this->assertEqualsWithDelta(100.0, $cash->balance(), 0.0005, 'debit-normal cash must carry a debit balance');
        $this->assertEqualsWithDelta(-100.0, $revenue->balance(), 0.0005, 'credit-normal revenue must carry a credit balance');
    }

    public function test_grouped_type_totals_match_the_per_account_sum(): void
    {
        $cash = Account::where('code', '1000')->firstOrFail();
        $revenue = Account::where('code', '4000')->firstOrFail();
        $expense = Account::where('code', '5100')->firstOrFail();

        $entry = JournalEntry::create([
            'number' => 'JE-TEST-2',
            'entry_date' => now()->toDateString(),
            'description' => 'Rent paid',
            'status' => 'draft',
            'created_by' => $this->userForRole('Accountant')->id,
        ]);

        GeneralLedger::replaceLines($entry, [
            ['account_id' => $expense->id, 'debit' => '250.00', 'credit' => '0.00', 'memo' => 'Rent'],
            ['account_id' => $cash->id, 'debit' => '0.00', 'credit' => '250.00', 'memo' => 'Rent paid'],
        ]);

        GeneralLedger::post($entry->fresh());

        $grouped = Account::balancesByType();

        // The grouped query replaced a 5 + 2N loop, so it has to agree with the
        // per-account calculation it replaced.
        $expected = collect(Account::typeOptions())->mapWithKeys(
            fn (string $type): array => [$type => round(
                Account::query()->where('type', $type)->get()->sum(fn (Account $a) => $a->balance()),
                2
            )]
        )->all();

        $this->assertSame($expected, $grouped);
    }

    public function test_draft_journals_are_excluded_from_type_totals(): void
    {
        $expense = Account::where('code', '5300')->firstOrFail();

        $entry = JournalEntry::create([
            'number' => 'JE-TEST-3',
            'entry_date' => now()->toDateString(),
            'description' => 'Unposted supplies',
            'status' => 'draft',
            'created_by' => $this->userForRole('Accountant')->id,
        ]);

        GeneralLedger::replaceLines($entry, [
            ['account_id' => $expense->id, 'debit' => '75.00', 'credit' => '0.00', 'memo' => 'Supplies'],
            ['account_id' => Account::where('code', '2100')->firstOrFail()->id, 'debit' => '0.00', 'credit' => '75.00', 'memo' => 'Payable'],
        ]);

        $this->assertSame(
            0.0,
            Account::balancesByType()['expense'],
            'a draft entry must not move the reported totals',
        );
    }
}
