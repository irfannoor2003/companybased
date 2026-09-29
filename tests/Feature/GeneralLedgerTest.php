<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Support\GeneralLedger;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Double-entry is the backbone of the accounting module, and its invariants were
 * previously only exercised indirectly through ChartOfAccountsTest. These tests
 * pin the rules directly.
 *
 * The invariants:
 *  - a journal cannot be posted unless debits equal credits and are non-zero
 *  - a line carries a debit or a credit, never both and never neither
 *  - lines may only reference active accounts
 *  - a posted entry cannot be re-posted, and a draft cannot be voided
 *  - voiding reverses the entry's effect on account balances
 */
class GeneralLedgerTest extends TestCase
{
    use SeedsDatabase;

    private function entry(string $status = 'draft', string $description = 'Test entry'): JournalEntry
    {
        return JournalEntry::create([
            'number' => 'JE-'.uniqid(),
            'entry_date' => now()->toDateString(),
            'description' => $description,
            'status' => $status,
            'created_by' => $this->userForRole('Accountant')->id,
        ]);
    }

    private function cash(): Account
    {
        return Account::where('code', '1000')->firstOrFail();
    }

    private function revenue(): Account
    {
        return Account::where('code', '4000')->firstOrFail();
    }

    private function expense(): Account
    {
        return Account::where('code', '5100')->firstOrFail();
    }

    private function balancedLines(): array
    {
        return [
            ['account_id' => $this->cash()->id, 'debit' => '100.00', 'credit' => '0.00', 'memo' => 'Cash'],
            ['account_id' => $this->revenue()->id, 'debit' => '0.00', 'credit' => '100.00', 'memo' => 'Revenue'],
        ];
    }

    public function test_a_balanced_entry_can_be_posted(): void
    {
        $entry = $this->entry();
        GeneralLedger::replaceLines($entry, $this->balancedLines());

        $this->assertTrue(GeneralLedger::isBalanced($entry->fresh()));

        GeneralLedger::post($entry);

        $this->assertSame('posted', $entry->fresh()->status);
        $this->assertNotNull($entry->fresh()->posted_at);
    }

    public function test_an_unbalanced_entry_cannot_be_posted(): void
    {
        $entry = $this->entry();
        GeneralLedger::replaceLines($entry, [
            ['account_id' => $this->cash()->id, 'debit' => '100.00', 'credit' => '0.00'],
        ]);

        $this->assertFalse(GeneralLedger::isBalanced($entry->fresh()));

        $this->expectException(\RuntimeException::class);

        GeneralLedger::post($entry);
    }

    public function test_an_entry_with_no_lines_cannot_be_posted(): void
    {
        $entry = $this->entry();

        $this->assertFalse(GeneralLedger::isBalanced($entry->fresh()), 'a zero-value entry is not balanced');

        $this->expectException(\RuntimeException::class);

        GeneralLedger::post($entry);
    }

    public function test_a_line_cannot_carry_both_a_debit_and_a_credit(): void
    {
        $entry = $this->entry();

        $this->expectException(\RuntimeException::class);

        GeneralLedger::replaceLines($entry, [
            ['account_id' => $this->cash()->id, 'debit' => '100.00', 'credit' => '100.00'],
        ]);
    }

    public function test_a_line_cannot_be_empty(): void
    {
        $entry = $this->entry();

        $this->expectException(\RuntimeException::class);

        GeneralLedger::replaceLines($entry, [
            ['account_id' => $this->cash()->id, 'debit' => '0.00', 'credit' => '0.00'],
        ]);
    }

    public function test_a_line_must_reference_an_active_account(): void
    {
        $expense = $this->expense();
        $expense->update(['is_active' => false]);

        $entry = $this->entry();

        $this->expectException(\RuntimeException::class);

        GeneralLedger::replaceLines($entry, [
            ['account_id' => $this->cash()->id, 'debit' => '50.00', 'credit' => '0.00'],
            ['account_id' => $expense->id, 'debit' => '0.00', 'credit' => '50.00'],
        ]);
    }

    public function test_a_rejected_line_leaves_the_previous_lines_intact(): void
    {
        $entry = $this->entry();
        GeneralLedger::replaceLines($entry, $this->balancedLines());
        $this->assertCount(2, $entry->fresh()->items);

        $inactive = $this->expense();
        $inactive->update(['is_active' => false]);

        try {
            GeneralLedger::replaceLines($entry, [
                ['account_id' => $this->cash()->id, 'debit' => '999.00', 'credit' => '0.00'],
                ['account_id' => $inactive->id, 'debit' => '0.00', 'credit' => '999.00'],
            ]);
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertCount(2, $entry->fresh()->items, 'the delete and re-insert must roll back together');
        $this->assertEqualsWithDelta(
            100.0,
            (float) $entry->fresh()->items->sum('debit'),
            0.0005,
            'the original balanced lines must survive a failed replacement',
        );
    }

    public function test_a_posted_entry_cannot_be_posted_again(): void
    {
        $entry = $this->entry();
        GeneralLedger::replaceLines($entry, $this->balancedLines());
        GeneralLedger::post($entry);

        $this->expectException(\RuntimeException::class);

        GeneralLedger::post($entry);
    }

    public function test_a_draft_entry_cannot_be_voided(): void
    {
        $entry = $this->entry();

        $this->expectException(\RuntimeException::class);

        GeneralLedger::void($entry);
    }

    public function test_voiding_a_posted_entry_reverses_its_effect(): void
    {
        $entry = $this->entry();
        GeneralLedger::replaceLines($entry, $this->balancedLines());
        GeneralLedger::post($entry);

        $this->assertEqualsWithDelta(100.0, $this->cash()->balance(), 0.0005);

        GeneralLedger::void($entry);

        $this->assertSame('void', $entry->fresh()->status);
        $this->assertEqualsWithDelta(
            0.0,
            $this->cash()->balance(),
            0.0005,
            'a voided entry must no longer count toward account balances',
        );
    }

    public function test_replace_lines_swaps_the_previous_set(): void
    {
        $entry = $this->entry();
        GeneralLedger::replaceLines($entry, $this->balancedLines());

        GeneralLedger::replaceLines($entry, [
            ['account_id' => $this->expense()->id, 'debit' => '25.00', 'credit' => '0.00'],
            ['account_id' => $this->cash()->id, 'debit' => '0.00', 'credit' => '25.00'],
        ]);

        $this->assertCount(2, $entry->fresh()->items, 'lines must be replaced, not appended');
        $this->assertEqualsWithDelta(25.0, (float) $entry->fresh()->items->sum('debit'), 0.0005);
    }

    public function test_amounts_are_stored_to_two_decimal_places(): void
    {
        $entry = $this->entry();
        GeneralLedger::replaceLines($entry, [
            ['account_id' => $this->cash()->id, 'debit' => '10.005', 'credit' => '0.00'],
            ['account_id' => $this->revenue()->id, 'debit' => '0.00', 'credit' => '10.005'],
        ]);

        foreach ($entry->fresh()->items as $line) {
            $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', (string) $line->debit);
            $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', (string) $line->credit);
        }
    }
}
