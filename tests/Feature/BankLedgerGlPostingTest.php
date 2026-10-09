<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\BankTransfer;
use App\Services\Accounting\LedgerPosting;
use App\Support\BankLedger;
use App\Support\Money;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Banking reaches the general ledger.
 *
 * This is the silent one. BankLedger wrote bank_transactions rows and stopped, so
 * a transfer moved money in the bank ledger while neither side of the balance sheet
 * moved. Every individual screen looked correct -- the account balance was right,
 * the transfer history was right -- and nothing compared the bank ledger against
 * the trial balance, because the bank ledger and the trial balance were two
 * unrelated sets of numbers that happened to both exist.
 *
 * The tests below assert on the ledger side, because that is the side that was
 * wrong and the side a user never sees directly.
 */
class BankLedgerGlPostingTest extends TestCase
{
    use SeedsDatabase;

    private function ledgerAccount(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    private function bankAccount(string $name, string $ledgerCode, string $type = 'checking'): BankAccount
    {
        return BankAccount::create([
            'name' => $name,
            'account_type' => $type,
            'currency' => 'PKR',
            'opening_balance' => '0',
            'is_active' => true,
            'account_id' => $this->ledgerAccount($ledgerCode)->id,
        ]);
    }

    /**
     * Signed balance of a ledger account across every journal line.
     */
    private function ledgerBalance(string $code): string
    {
        $account = $this->ledgerAccount($code);
        $balance = '0.00';

        foreach ($account->journalItems()->get() as $line) {
            $balance = Money::add($balance, $line->debit);
            $balance = Money::subtract($balance, $line->credit);
        }

        return $balance;
    }

    private function transfer(BankAccount $from, BankAccount $to, string $amount): BankTransfer
    {
        return BankTransfer::create([
            'number' => 'TRF-'.uniqid(),
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => $amount,
            'transfer_date' => '2026-01-15',
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function test_a_transfer_reaches_the_balance_sheet(): void
    {
        $operating = $this->bankAccount('Operating', '1010');
        $payroll = $this->bankAccount('Payroll', '1010');

        // Both map to Bank, so the transfer nets to nothing overall but the two
        // journal lines must still exist.
        $transfer = $this->transfer($operating, $payroll, '5000.00');

        $entry = app(LedgerPosting::class)->bankTransferPosted($transfer);

        $this->assertNotNull($entry, 'a completed transfer produced no journal');
        $entry->load('items.account');

        $this->assertSame('5000.00', Money::add($entry->items->sum('debit'), '0.00'));
        $this->assertSame($entry->items->sum('debit'), $entry->items->sum('credit'), 'the transfer journal must balance');
    }

    /**
     * The core assertion: the balance sheet and the bank ledger must move together.
     *
     * Before this existed they were two unrelated sets of numbers, which is why
     * nothing caught the divergence -- the account balance was right and the
     * trial balance was wrong, and no page compared them.
     */
    public function test_the_bank_ledger_and_the_trial_balance_agree_after_a_transfer(): void
    {
        $operating = $this->bankAccount('Operating', '1010');
        $savings = $this->bankAccount('Savings', '1310');

        $operating->update(['opening_balance' => '10000.00']);

        $preSavings = $this->ledgerBalance('1310');

        $transfer = $this->transfer($operating, $savings, '2500.00');

        // Both halves of the real path: the bank legs, then the journal.
        BankLedger::postTransfer($transfer);
        app(LedgerPosting::class)->bankTransferPosted($transfer->fresh());

        $this->assertSame('2500.00', Money::subtract($this->ledgerBalance('1310'), $preSavings),
            'the ledger recorded a different amount from the one the bank ledger moved');

        $this->assertSame('7500.00', Money::of($operating->fresh()->balance()),
            'the bank ledger says 7500 after moving 2500 out of 10000');
    }

    public function test_a_transfer_cannot_post_twice(): void
    {
        $from = $this->bankAccount('From', '1010');
        $to = $this->bankAccount('To', '1310');

        $transfer = $this->transfer($from, $to, '750.00');

        $ledger = app(LedgerPosting::class);
        $first = $ledger->bankTransferPosted($transfer);
        $second = $ledger->bankTransferPosted($transfer);

        $this->assertNotNull($first);
        $this->assertNull($second, 'a repeated post duplicated the transfer journal');
    }

    /**
     * Without a mapping there is no correct account to post against. Guessing one
     * would attribute the transfer to an account nobody chose, so it is refused
     * and the reason is logged rather than swallowed.
     */
    public function test_an_unmapped_bank_account_is_refused_rather_than_guessed(): void
    {
        $unmapped = BankAccount::create([
            'name' => 'Unmapped',
            'account_type' => 'checking',
            'currency' => 'PKR',
            'opening_balance' => '0',
            'is_active' => true,
        ]);

        $mapped = $this->bankAccount('Mapped', '1010');

        $this->assertNull(
            app(LedgerPosting::class)->bankTransferPosted($this->transfer($unmapped, $mapped, '100.00')),
            'a transfer from an unmapped account was posted against a guessed ledger account',
        );
    }

    /**
     * Petty cash is not a bank account. It belongs in Cash on Hand, so an unmapped
     * cash account still posts correctly.
     */
    public function test_a_cash_account_falls_back_to_cash_on_hand(): void
    {
        $petty = BankAccount::create([
            'name' => 'Petty Cash',
            'account_type' => 'cash',
            'currency' => 'PKR',
            'opening_balance' => '0',
            'is_active' => true,
        ]);

        $this->assertSame('1000', $petty->ledgerCode());
    }

    /**
     * An unidentified deposit is money that arrived and nobody has explained yet.
     * Booking it as an expense would put a phantom cost on the P&L for every
     * deposit the business has not yet attributed.
     */
    public function test_a_manual_deposit_is_a_liability_not_a_cost(): void
    {
        $account = $this->bankAccount('Operating', '1010');

        $transaction = BankTransaction::create([
            'bank_account_id' => $account->id,
            'number' => 'BT-'.uniqid(),
            'transaction_date' => '2026-01-20',
            'type' => 'deposit',
            'amount' => '3000.00',
            'description' => 'Unidentified credit',
            'is_reconciled' => false,
        ]);

        $entry = app(LedgerPosting::class)->bankTransactionPosted($transaction);
        $this->assertNotNull($entry);
        $entry->load('items.account');

        $byCode = $entry->items->groupBy(fn ($i) => $i->account->code);

        $this->assertSame('3000.00', $byCode['1010']->first()->debit, 'the bank balance rises');
        $this->assertSame('3000.00', $byCode['2050']->first()->credit, 'held as an obligation, not expensed');
        $this->assertSame($entry->items->sum('debit'), $entry->items->sum('credit'));
    }

    public function test_a_manual_withdrawal_is_posted_the_other_way_round(): void
    {
        $account = $this->bankAccount('Operating', '1010');

        $transaction = BankTransaction::create([
            'bank_account_id' => $account->id,
            'number' => 'BT-'.uniqid(),
            'transaction_date' => '2026-01-20',
            'type' => 'withdrawal',
            'amount' => '150.00',
            'description' => 'Direct debit',
            'is_reconciled' => false,
        ]);

        $entry = app(LedgerPosting::class)->bankTransactionPosted($transaction);
        $entry->load('items.account');

        $byCode = $entry->items->groupBy(fn ($i) => $i->account->code);

        $this->assertSame('150.00', $byCode['1010']->first()->credit, 'money left the bank');
        $this->assertSame('150.00', $byCode['2050']->first()->debit);
    }

    /**
     * A bank charge is a known cost, so the caller may override the offset rather
     * than leaving every manual withdrawal sitting as a liability forever.
     */
    public function test_a_known_offset_account_can_be_supplied(): void
    {
        $account = $this->bankAccount('Operating', '1010');

        $transaction = BankTransaction::create([
            'bank_account_id' => $account->id,
            'number' => 'BT-'.uniqid(),
            'transaction_date' => '2026-01-20',
            'type' => 'withdrawal',
            'amount' => '25.00',
            'description' => 'Monthly account fee',
            'is_reconciled' => false,
        ]);

        $entry = app(LedgerPosting::class)
            ->bankTransactionPosted($transaction, LedgerPosting::BANK_CHARGES);
        $entry->load('items.account');

        $codes = $entry->items->pluck('account.code')->all();

        $this->assertContains('6300', $codes, 'a bank charge should hit Bank Charges, not Uncategorised');
        $this->assertNotContains('2050', $codes);
    }

    public function test_the_journal_is_stamped_on_the_transaction(): void
    {
        $account = $this->bankAccount('Operating', '1010');

        $transaction = BankTransaction::create([
            'bank_account_id' => $account->id,
            'number' => 'BT-'.uniqid(),
            'transaction_date' => '2026-01-20',
            'type' => 'deposit',
            'amount' => '60.00',
            'is_reconciled' => false,
        ]);

        $entry = app(LedgerPosting::class)->bankTransactionPosted($transaction);

        $this->assertNotNull($entry);
        $this->assertSame($entry->id, $transaction->fresh()->journal_entry_id);
    }

    public function test_reversing_a_bank_movement_voids_its_journal(): void
    {
        $account = $this->bankAccount('Operating', '1010');

        $transaction = BankTransaction::create([
            'bank_account_id' => $account->id,
            'number' => 'BT-'.uniqid(),
            'transaction_date' => '2026-01-20',
            'type' => 'deposit',
            'amount' => '900.00',
            'is_reconciled' => false,
        ]);

        $ledger = app(LedgerPosting::class);
        $ledger->bankTransactionPosted($transaction);

        $this->assertTrue($ledger->reverseBankMovement($transaction->fresh()));
        $this->assertSame('void', $transaction->fresh()->journalEntry->status);
    }

    public function test_a_zero_amount_posting_is_skipped(): void
    {
        $from = $this->bankAccount('From', '1010');
        $to = $this->bankAccount('To', '1310');

        $this->assertNull(
            app(LedgerPosting::class)->bankTransferPosted($this->transfer($from, $to, '0.00')),
        );
    }

    public function test_the_bank_account_can_be_mapped_on_create(): void
    {
        $ledgerAccount = $this->ledgerAccount('1010');

        $response = $this->actingAs($this->userForRole('Admin'))
            ->post('/banking/accounts', [
                'name' => 'New Operating',
                'account_type' => 'checking',
                'currency' => 'PKR',
                'opening_balance' => '1000',
                'is_active' => '1',
                'account_id' => $ledgerAccount->id,
            ]);

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('bank_accounts', [
            'name' => 'New Operating',
            'account_id' => $ledgerAccount->id,
        ]);
    }
}