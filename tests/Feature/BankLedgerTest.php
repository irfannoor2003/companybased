<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\BankTransfer;
use App\Support\BankLedger;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * A bank transfer posts two legs — money out of one account, into another — and
 * both must land together or neither should. This is the second of the three
 * ledgers (with InventoryLedger and GeneralLedger) and it had no tests.
 *
 * The invariants:
 *  - a transfer creates exactly two legs, opposite in type and equal in amount
 *  - the legs are traceable back to the transfer
 *  - reversing removes both legs
 *  - a transfer is overall stock-neutral for the company
 */
class BankLedgerTest extends TestCase
{
    use SeedsDatabase;

    private function account(string $name, float $opening = 0.0): BankAccount
    {
        return BankAccount::create([
            'name' => $name,
            'account_number' => strtoupper(substr(md5($name), 0, 8)),
            'bank_name' => 'Test Bank',
            'currency' => 'PKR',
            'account_type' => 'checking',
            'opening_balance' => (string) $opening,
            'is_active' => true,
        ]);
    }

    private function transfer(BankAccount $from, BankAccount $to, string $amount = '500.00'): BankTransfer
    {
        return BankTransfer::create([
            'number' => 'BT-'.uniqid(),
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'transfer_date' => now()->toDateString(),
            'amount' => $amount,
            'status' => 'draft',
        ]);
    }

    public function test_a_transfer_posts_two_opposite_legs(): void
    {
        $from = $this->account('Current', 2000.00);
        $to = $this->account('Savings', 0.0);
        $transfer = $this->transfer($from, $to, '500.00');

        BankLedger::postTransfer($transfer);

        $legs = BankTransaction::where('reference_type', $transfer->getMorphClass())
            ->where('reference_id', $transfer->id)
            ->get();

        $this->assertCount(2, $legs, 'a transfer is always two legs');

        $out = $legs->firstWhere('type', 'transfer_out');
        $in = $legs->firstWhere('type', 'transfer_in');

        $this->assertNotNull($out);
        $this->assertNotNull($in);
        $this->assertEqualsWithDelta(500.0, (float) $out->amount, 0.0005);
        $this->assertEqualsWithDelta(500.0, (float) $in->amount, 0.0005);
    }

    public function test_the_legs_land_in_the_right_accounts(): void
    {
        $from = $this->account('Current', 2000.00);
        $to = $this->account('Savings', 0.0);
        $transfer = $this->transfer($from, $to, '750.00');

        BankLedger::postTransfer($transfer);

        $out = BankTransaction::where('type', 'transfer_out')->firstOrFail();
        $in = BankTransaction::where('type', 'transfer_in')->firstOrFail();

        $this->assertSame($from->id, $out->bank_account_id, 'money leaves the source account');
        $this->assertSame($to->id, $in->bank_account_id, 'money arrives in the destination account');
    }

    public function test_the_account_balances_move_in_opposite_directions(): void
    {
        $from = $this->account('Current', 2000.00);
        $to = $this->account('Savings', 0.0);
        $transfer = $this->transfer($from, $to, '500.00');

        BankLedger::postTransfer($transfer);

        $this->assertEqualsWithDelta(1500.0, $from->fresh()->balance(), 0.0005);
        $this->assertEqualsWithDelta(500.0, $to->fresh()->balance(), 0.0005);
    }

    public function test_a_transfer_is_neutral_across_the_company(): void
    {
        $from = $this->account('Current', 2000.00);
        $to = $this->account('Savings', 0.0);
        $transfer = $this->transfer($from, $to, '500.00');

        BankLedger::postTransfer($transfer);

        $before = 2000.00;
        $after = (float) $from->fresh()->balance() + (float) $to->fresh()->balance();

        $this->assertEqualsWithDelta($before, $after, 0.0005, 'money moves, it is not created');
    }

    public function test_reversing_a_transfer_removes_both_legs(): void
    {
        $from = $this->account('Current', 2000.00);
        $to = $this->account('Savings', 0.0);
        $transfer = $this->transfer($from, $to, '500.00');

        BankLedger::postTransfer($transfer);
        BankLedger::reverseTransfer($transfer);

        $this->assertSame(0, BankTransaction::where('reference_type', $transfer->getMorphClass())->count());
        $this->assertEqualsWithDelta(2000.0, $from->fresh()->balance(), 0.0005);
        $this->assertEqualsWithDelta(0.0, $to->fresh()->balance(), 0.0005);
    }

    public function test_reversing_an_unposted_transfer_is_harmless(): void
    {
        $from = $this->account('Current', 2000.00);
        $to = $this->account('Savings', 0.0);
        $transfer = $this->transfer($from, $to, '500.00');

        BankLedger::reverseTransfer($transfer);

        $this->assertSame(0, BankTransaction::count());
    }

    public function test_each_leg_carries_its_own_document_number(): void
    {
        $from = $this->account('Current', 2000.00);
        $to = $this->account('Savings', 0.0);
        $transfer = $this->transfer($from, $to, '500.00');

        BankLedger::postTransfer($transfer);

        $numbers = BankTransaction::pluck('number')->all();

        $this->assertCount(2, $numbers);
        $this->assertCount(2, array_unique($numbers), 'transaction numbers must be unique');
    }

    public function test_new_legs_start_unreconciled(): void
    {
        $from = $this->account('Current', 2000.00);
        $to = $this->account('Savings', 0.0);
        $transfer = $this->transfer($from, $to, '500.00');

        BankLedger::postTransfer($transfer);

        $this->assertSame(0, BankTransaction::where('is_reconciled', true)->count());
    }
}
