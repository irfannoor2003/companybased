<?php

namespace Database\Seeders;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\BankTransfer;
use App\Models\Reconciliation;
use App\Models\ReconciliationItem;
use App\Support\BankLedger;
use Illuminate\Database\Seeder;

class BankingSeeder extends Seeder
{
    /**
     * Demo banking data: accounts, transactions, a completed transfer and a
     * reconciled statement. Money stays in decimal strings — never floats.
     */
    public function run(): void
    {
        if (BankAccount::exists() || BankTransaction::exists()) {
            return;
        }

        $checking = BankAccount::create([
            'name' => 'Main Operating',
            'account_number' => 'US-889123456',
            'bank_name' => 'First National',
            'branch' => 'Downtown',
            'account_type' => 'checking',
            'currency' => 'USD',
            'opening_balance' => '25000.00',
            'is_active' => true,
            'notes' => 'Primary operating account.',
        ]);

        $savings = BankAccount::create([
            'name' => 'Growth Savings',
            'account_number' => 'US-889654321',
            'bank_name' => 'First National',
            'branch' => 'Downtown',
            'account_type' => 'savings',
            'currency' => 'USD',
            'opening_balance' => '5000.00',
            'is_active' => true,
            'notes' => 'Reserve funds.',
        ]);

        $cash = BankAccount::create([
            'name' => 'Petty Cash',
            'account_number' => null,
            'bank_name' => null,
            'branch' => null,
            'account_type' => 'cash',
            'currency' => 'USD',
            'opening_balance' => '500.00',
            'is_active' => true,
            'notes' => 'On-hand cash float.',
        ]);

        $this->transaction($checking, now()->subDays(12), 'deposit', '15000.00', 'Apex Corp', 'Invoice payment INV-2026-0001', 'ACH-118200');
        $this->transaction($checking, now()->subDays(10), 'deposit', '4200.00', 'Brightline Retail', 'Invoice payment INV-2026-0002', 'ACH-118311');
        $this->transaction($checking, now()->subDays(8), 'withdrawal', '2300.00', 'Bluepine Industrial', 'Supplier payment SP-2026-0001', 'ACH-118400');
        $this->transaction($checking, now()->subDays(6), 'withdrawal', '850.00', 'Landlord', 'Office rent', 'CHQ-4412');
        $this->transaction($checking, now()->subDays(4), 'deposit', '980.00', 'Summit Retail', 'Invoice payment INV-2026-0003', 'ACH-118566');
        $this->transaction($checking, now()->subDays(2), 'withdrawal', '410.00', 'Utilities Co', 'Electricity bill', 'ACH-118601');
        $this->transaction($cash, now()->subDays(3), 'withdrawal', '120.00', 'Stationery Shop', 'Office supplies', null);
        $this->transaction($cash, now()->subDays(1), 'deposit', '200.00', 'Reimbursement', 'Float top-up', null);

        $transfer = BankTransfer::create([
            'number' => next_document_number('bank_transfer', 'XFR'),
            'transfer_date' => now()->subDays(5)->toDateString(),
            'from_account_id' => $checking->id,
            'to_account_id' => $savings->id,
            'amount' => '3000.00',
            'description' => 'Monthly reserve contribution.',
            'status' => 'completed',
            'completed_at' => now()->subDays(5),
        ]);
        BankLedger::postTransfer($transfer);

        $checking->load('transactions');
        $balance = $checking->balance();
        $inScope = $checking->transactions()->orderBy('transaction_date')->get();

        $reconciliation = Reconciliation::create([
            'number' => next_document_number('reconciliation', 'REC'),
            'bank_account_id' => $checking->id,
            'statement_date' => now()->toDateString(),
            'opening_balance' => $checking->opening_balance,
            'statement_ending_balance' => $balance,
            'status' => 'completed',
            'notes' => 'Opening reconciliation for the operating account.',
        ]);

        foreach ($inScope as $transaction) {
            ReconciliationItem::create([
                'reconciliation_id' => $reconciliation->id,
                'bank_transaction_id' => $transaction->id,
                'is_cleared' => true,
            ]);
            $transaction->update(['is_reconciled' => true, 'reconciled_at' => now()]);
        }
    }

    private function transaction(BankAccount $account, \DateTimeInterface $date, string $type, string $amount, string $counterparty, ?string $description, ?string $reference): void
    {
        BankTransaction::create([
            'bank_account_id' => $account->id,
            'number' => next_document_number('bank_transaction', 'BT'),
            'transaction_date' => $date->toDateString(),
            'type' => $type,
            'amount' => $amount,
            'counterparty' => $counterparty,
            'description' => $description,
            'reference' => $reference,
            'is_reconciled' => false,
        ]);
    }
}
