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
            $entry->items()->delete();

            foreach ($lines as $line) {
                if (! isset($line['account_id']) || (! $line['debit'] && ! $line['credit'])) {
                    continue;
                }

                $account = Account::find($line['account_id']);

                if (! $account || ! $account->is_active) {
                    continue;
                }

                JournalEntryItem::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $account->id,
                    'debit' => (string) ($line['debit'] ?? 0),
                    'credit' => (string) ($line['credit'] ?? 0),
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
            if ($entry->status !== 'posted') {
                throw new \RuntimeException('Only posted entries can be voided.');
            }

            $entry->update(['status' => 'void']);
        });
    }
}