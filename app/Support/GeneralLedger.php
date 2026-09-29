<?php

namespace App\Support;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryItem;
use Illuminate\Support\Facades\DB;

/**
 * Posts double-entry journal lines atomically. Every journal must balance
 * (debits === credits) before it can be posted. Amounts stay decimal strings.
 */
class GeneralLedger
{
    /**
     * Replace the lines of a journal with a new balanced set.
     *
     * @param  array<int, array{account_id: int, debit: string|float, credit: string|float, memo: ?string}>  $lines
     */
    public static function replaceLines(JournalEntry $entry, array $lines): void
    {
        DB::transaction(function () use ($entry, $lines) {
            $entry = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $entry->items()->delete();

            foreach ($lines as $index => $line) {
                $debit = (float) ($line['debit'] ?? 0);
                $credit = (float) ($line['credit'] ?? 0);
                $accountId = $line['account_id'] ?? null;

                if (! $accountId || ($debit <= 0 && $credit <= 0) || ($debit > 0 && $credit > 0)) {
                    throw new \RuntimeException('Each journal line must contain a positive debit or credit, not both.');
                }

                $account = Account::find($accountId);
                if (! $account || ! $account->is_active) {
                    throw new \RuntimeException('Journal line '.($index + 1).' references an invalid or inactive account.');
                }

                JournalEntryItem::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $account->id,
                    'debit' => number_format($debit, 2, '.', ''),
                    'credit' => number_format($credit, 2, '.', ''),
                    'memo' => $line['memo'] ?? null,
                ]);
            }
        });
    }

    /**
     * Validate that a draft entry is balanced and has a value.
     */
    public static function isBalanced(JournalEntry $entry): bool
    {
        return $entry->isBalanced();
    }

    public static function post(JournalEntry $entry): void
    {
        DB::transaction(function () use ($entry) {
            $entry = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);
            if ($entry->status !== 'draft') {
                throw new \RuntimeException('Only draft entries can be posted.');
            }

            if (! $entry->isBalanced()) {
                throw new \RuntimeException('Journal must balance before posting.');
            }

            $entry->update([
                'status' => 'posted',
                'posted_at' => now(),
            ]);
        });
    }

    public static function void(JournalEntry $entry): void
    {
        DB::transaction(function () use ($entry) {
            $entry = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);
            if ($entry->status !== 'posted') {
                throw new \RuntimeException('Only posted entries can be voided.');
            }

            $entry->update(['status' => 'void']);
        });
    }
}
