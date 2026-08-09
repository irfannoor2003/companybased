<?php

namespace App\Support;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\BankTransfer;
use Illuminate\Support\Facades\DB;

/**
 * Posts money movements to bank transaction ledgers. Transfers always move
 * as two atomic legs (transfer_out on source, transfer_in on destination)
 * and are fully reversible. Amounts stay decimal strings — never floats.
 */
class BankLedger
{
    public static function postTransfer(BankTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer) {
            $transfer->fromAccount?->load('transactions');
            $transfer->toAccount?->load('transactions');

            static::leg($transfer->from_account_id, $transfer->amount, 'transfer_out', $transfer, 'Transfer to '.($transfer->toAccount?->name ?? 'account'));
            static::leg($transfer->to_account_id, $transfer->amount, 'transfer_in', $transfer, 'Transfer from '.($transfer->fromAccount?->name ?? 'account'));
        });
    }

    public static function reverseTransfer(BankTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer) {
            BankTransaction::query()
                ->where('reference_type', $transfer->getMorphClass())
                ->where('reference_id', $transfer->id)
                ->delete();
        });
    }

    private static function leg(int $accountId, string $amount, string $type, BankTransfer $transfer, string $description): void
    {
        BankTransaction::create([
            'bank_account_id' => $accountId,
            'number' => next_document_number('bank_transaction', 'BT'),
            'transaction_date' => $transfer->transfer_date,
            'type' => $type,
            'amount' => $amount,
            'counterparty' => $type === 'transfer_out' ? $transfer->toAccount?->name : $transfer->fromAccount?->name,
            'description' => $description,
            'reference' => $transfer->number,
            'is_reconciled' => false,
            'reference_type' => $transfer->getMorphClass(),
            'reference_id' => $transfer->id,
        ]);
    }
}
