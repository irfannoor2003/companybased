<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Support\GeneralLedger;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Close a fiscal year by moving the profit and loss into retained earnings.
 *
 * Until this existed the balance sheet computed retained earnings as
 * `assets - liabilities - equity`, which forces the statement to balance by
 * arithmetic whatever the ledger says. A transposition, a misposting or a missing
 * entry would simply move the retained earnings line to hide it, so the balance
 * sheet always agreed and never told you anything.
 *
 * With a real closing entry the figure is the accumulated profit the business
 * actually made, and a broken ledger shows up as an unbalanced balance sheet
 * instead of being quietly absorbed.
 *
 * The entry itself:
 *
 *   Dr each revenue account      its credit balance   (close the credit)
 *   Dr each expense account      its debit balance    (close the debit)
 *       Cr Retained Earnings                             net profit, or
 *   Dr Retained Earnings                                net loss
 *
 * Only `posted` journals, and only those dated on or before the period end, so a
 * draft in progress cannot be folded into a closed year.
 *
 * Idempotent per period: closing the same year twice is refused rather than
 * double-counting, because a second close would credit retained earnings again
 * with nothing left in the P&L to justify it.
 */
class YearEndClose
{
    /**
     * P&L account types closed into retained earnings.
     *
     * Anything of type `asset`, `liability` or `equity` is a balance, not a period
     * movement, and is left exactly where it is.
     */
    private const CLOSED_TYPES = ['revenue', 'expense'];

    private function ledger(): LedgerPosting
    {
        return new LedgerPosting();
    }

    /**
     * Close a period and return the resulting journal.
     *
     * @param  string  $periodEnd  inclusive; anything dated after this is left open
     */
    public function close(string $periodEnd, ?string $reference = null): ?Model
    {
        if ($this->alreadyClosed($periodEnd, $reference)) {
            return null;
        }

        $ledger = $this->ledger();

        if (! $ledger->isReady()) {
            Log::warning('Year-end close skipped: chart of accounts is incomplete.');

            return null;
        }

        $rows = $this->profitAndLossBalances($periodEnd);

        if ($rows === []) {
            return null;
        }

        $lines = [];
        $profit = '0.00';

        foreach ($rows as $row) {
            /**
             * A revenue account is credit-normal, so its balance is negative under
             * the debit-positive convention. Closing it means DEBITING the account
             * to flatten it, and every rupee closed that way was income -- so it is
             * ADDED to the result.
             *
             * An expense is debit-normal: closing it CREDITS the account, and every
             * rupee closed that way was cost, so it is SUBTRACTED.
             *
             * Getting these two the wrong way round produces a "profit" that is
             * really the loss, debits the retained earnings account, and leaves the
             * closing journal unbalanced — which is what the first version did.
             */
            if ($row->side === 'credit') {
                $lines[] = ['code' => $row->code, 'debit' => $row->amount];
                $profit = Money::add($profit, $row->amount);
            } else {
                $lines[] = ['code' => $row->code, 'credit' => $row->amount];
                $profit = Money::subtract($profit, $row->amount);
            }
        }

        if (Money::equals($profit, '0')) {
            return null; // A closed year with no result needs no entry.
        }

        // A loss debits retained earnings rather than crediting it. Crediting a
        // liability-shaped account would report the company as owing itself money.
        $lines[] = Money::isGreaterThan($profit, '0')
            ? ['code' => LedgerPosting::RETAINED_EARNINGS, 'credit' => $profit]
            : ['code' => LedgerPosting::RETAINED_EARNINGS, 'debit' => Money::multiply($profit, '-1')];

        return $this->postClose($lines, $periodEnd, $reference);
    }

    /**
     * A period already closed carries a journal with the close reference, so a
     * second run is a no-op rather than a second credit to retained earnings.
     */
    private function alreadyClosed(string $periodEnd, ?string $reference): bool
    {
        return JournalEntry::query()
            ->where('description', 'like', 'Year-end close%')
            ->where('reference', $reference ?: ('year-end-close:'.$periodEnd))
            ->where('status', 'posted')
            ->exists();
    }

    /**
     * Each revenue and expense account's balance on the close date.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function profitAndLossBalances(string $periodEnd)
    {
        return DB::table('journal_entry_items')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_items.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_entry_items.account_id')
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.entry_date', '<=', $periodEnd)
            ->whereIn('accounts.type', self::CLOSED_TYPES)
            ->groupBy('accounts.code', 'accounts.type')
            ->selectRaw('accounts.code')
            ->selectRaw('accounts.type')
            ->selectRaw('COALESCE(SUM(journal_entry_items.debit - journal_entry_items.credit), 0) AS balance')
            ->get()
            ->map(function ($row) {
                $balance = (float) $row->balance;

                return (object) [
                    'code' => $row->code,
                    // A revenue account is credit-normal: its balance is negative
                    // under the debit-positive convention, which is what identifies
                    // the side to close it from.
                    'side' => $balance >= 0 ? 'debit' : 'credit',
                    'amount' => number_format(abs($balance), 2, '.', ''),
                ];
            })
            ->filter(fn ($row) => (float) $row->amount !== 0.0)
            ->values();
    }

    /**
     * Raise the closing entry.
     *
     * Posted through GeneralLedger rather than LedgerPosting because a close has no
     * source document to key idempotency on -- it is keyed on the period, which
     * `alreadyClosed()` above handles.
     *
     * @param  array<int, array<string, string>>  $lines
     */
    private function postClose(array $lines, string $periodEnd, ?string $reference): Model
    {
        return DB::transaction(function () use ($lines, $periodEnd, $reference) {
            $entry = JournalEntry::create([
                'number' => next_document_number('journal', 'JV'),
                'entry_date' => $periodEnd,
                'reference' => $reference ?: ('year-end-close:'.$periodEnd),
                'description' => 'Year-end close to '.$periodEnd,
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]);

            $resolved = [];

            foreach ($lines as $line) {
                $account = Account::where('code', $line['code'])->first();

                if ($account === null) {
                    continue;
                }

                $resolved[] = [
                    'account_id' => $account->id,
                    'debit' => $line['debit'] ?? '0.00',
                    'credit' => $line['credit'] ?? '0.00',
                ];
            }

            GeneralLedger::replaceLines($entry, $resolved);
            GeneralLedger::post($entry);

            /**
             * `post()` locks and reloads the row into its own local instance to guard
             * the status check, so it updates that copy rather than this one. Without
             * the refresh the returned model still reports `draft` even though the
             * entry is committed as posted.
             */
            $entry->refresh();

            return $entry;
        });
    }
}
