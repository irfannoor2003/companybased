<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Support\GeneralLedger;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Revalues open foreign-currency balances at a closing rate.
 *
 * A document is posted at the rate it was raised at, which is correct and must
 * never be changed -- that rate is history. But a balance that is still open at
 * period end is measured at today's rate, not at the rate it happened to be
 * created at. Where the two differ, the balance sheet carries a figure in the
 * reporting currency that no longer describes the underlying foreign money.
 *
 * The adjustment:
 *
 *   carried base amount   = sum of original amounts x their snapshotted rates
 *   closing base amount   = net original amount x the closing rate
 *   difference            = closing - carried
 *
 * Only the *difference* is posted. Rewriting the carried amount would mean
 * restating the transactions that created the balance, which is not something a
 * revaluation is allowed to do.
 *
 * What is revalued, and what is not:
 *
 *  - Monetary asset and liability balances only. Revenue and expense are
 *    translated at the rates of the transactions that produced them; adjusting
 *    them for a rate movement is a different exercise entirely.
 *  - Contra accounts and accumulated depreciation are excluded. Their balances
 *    are an accumulation of other accounts' movements, and revaluing both sides
 *    of a fixed-asset pair would leave the net figure untraceable to either.
 *  - An account's whole foreign balance, not individual transactions. Revaluing
 *    line by line would leave a residual on every account, because a gain on one
 *    transaction and a loss on another net differently once rounded.
 */
class RevaluationService
{
    /**
     * Account sub-types that hold a monetary balance.
     *
     * Fixed-asset and contra sub-types are deliberately absent; see the class
     * comment.
     */
    private const MONETARY_SUB_TYPES = ['current'];

    /**
     * The adjustment required for one account in one currency.
     *
     * @return array{account_code: string, account_name: string, currency: string, net_original: string, carried: string, closing_rate: string, closing: string, difference: string, gain: bool}
     */
    public function adjustmentFor(Account $account, string $currency, Carbon $asAt): ?array
    {
        $base = base_currency();

        if (strtoupper($currency) === $base) {
            return null;
        }

        $net = DB::table('journal_entry_items')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_items.journal_entry_id')
            ->where('journal_entry_items.account_id', $account->id)
            ->where('journal_entry_items.currency', strtoupper($currency))
            ->where('journal_entries.status', 'posted')
            ->where('journal_entries.entry_date', '<=', $asAt->toDateString())
            ->selectRaw('COALESCE(SUM(original_debit - original_credit), 0) AS net_original')
            ->selectRaw('COALESCE(SUM(debit - credit), 0) AS carried')
            ->first();

        $netOriginal = Money::of($net->net_original);

        // No foreign balance means nothing to revalue. Including it would post a
        // zero-line journal for every account on every close.
        if (Money::equals($netOriginal, '0')) {
            return null;
        }

        $closingRate = $this->closingRateFor($currency, $asAt);

        /**
         * No closing rate means no revaluation, rather than a rate of 1.
         *
         * Defaulting to 1 would value every foreign balance at parity with the
         * reporting currency, which is both wrong and large -- and it would
         * silently override a perfectly good transaction rate.
         */
        if ($closingRate === null) {
            Log::warning(
                'Revaluation skipped for '.$currency.' on account '.$account->code
                .': no closing rate is configured for '.$asAt->toDateString().'.',
            );

            return null;
        }

        $carried = Money::of($net->carried);
        $closing = Money::of((float) $netOriginal * $closingRate);
        $difference = Money::subtract($closing, $carried);

        if (Money::equals($difference, '0')) {
            return null;
        }

/**
         * Gain or loss, from the sign of the difference alone.
         *
         * The figures are all net debit-positive, and that convention already
         * answers the question -- no separate debit/credit test is needed:
         *
         *   asset carried +250,000, closing +260,000  -> +10,000 -> gain
         *   asset carried +250,000, closing +240,000  -> -10,000 -> loss
         *   liability carried -250,000, closing -240,000 -> +10,000 -> gain
         *   liability carried -250,000, closing -260,000 -> -10,000 -> loss
         *
         * A liability is credit-natured, so it carries as negative. A movement
         * toward zero means less is owed, which is a gain -- and that is exactly
         * a positive net movement. Testing the account's balance direction
         * separately (as this did first) is how a falling payable gets booked as
         * a loss.
         */
        $gain = Money::isGreaterThan($difference, '0');

        return [
            'account_code' => $account->code,
            'account_name' => $account->name,
            'currency' => strtoupper($currency),
            'net_original' => $netOriginal,
            'carried' => $carried,
            'closing_rate' => (string) $closingRate,
            'closing' => $closing,
            'difference' => $difference,
            'gain' => $gain,
        ];
    }

    /**
     * Every adjustment required at the close of a period.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function adjustmentsFor(?FiscalPeriod $period = null, ?Carbon $asAt = null): Collection
    {
        $asAt ??= $period?->end_date ?? today();

        $accounts = Account::query()
            ->where('is_active', true)
            ->whereIn('type', ['asset', 'liability'])
            ->whereIn('sub_type', self::MONETARY_SUB_TYPES)
            ->orderBy('code')
            ->get();

        // Only currencies that actually have a foreign balance in a monetary
        // account are worth looking at, so the working set is bounded by the
        // ledger rather than by the currency table.
        $currencies = DB::table('journal_entry_items')
            ->where('currency', '!=', base_currency())
            ->distinct()
            ->pluck('currency')
            ->all();

        $adjustments = collect();

        foreach ($currencies as $currency) {
            foreach ($accounts as $account) {
                if ($adjustment = $this->adjustmentFor($account, $currency, $asAt)) {
                    $adjustments->push($adjustment);
                }
            }
        }

        return $adjustments;
    }

/**
 * Revalue and close, in that order.
     *
     * The order is the whole point. A revaluation entry belongs *inside* the
     * period it closes -- it is dated at the period end -- but a closed period
     * refuses every entry dated on or before its date_lock. So revaluing after
     * closing is impossible by construction, and revaluing before closing is the
     * only sequence that works: the adjustment is the last entry the period
     * accepts, which is exactly what a closing entry is.
     */
    public function closeWithRevaluation(
        FiscalPeriod $period,
        ?Carbon $asAt = null,
        ?string $reason = null,
    ): ?JournalEntry {
        if (! $period->isOpen()) {
            throw new \RuntimeException(
                'The period '.$period->label().' is not open, so it cannot be revalued and closed.'
            );
        }

        $entry = $this->post($period, $asAt);

        app(FiscalPeriodService::class)->close($period, auth()->user(), $reason);

        return $entry;
    }

    /**
     * Post the revaluation for an open period.
     *
     * Must run while the period is still open. See closeWithRevaluation() for why
     * revaluing a closed period cannot work.
     */
    public function post(FiscalPeriod $period, ?Carbon $asAt = null): ?JournalEntry
    {
        if (! $period->isOpen()) {
            throw new \RuntimeException(
                'The period '.$period->label().' is '.$period->status
                .', so it can no longer be revalued. A closing entry belongs inside the '
                .'period it closes, which means it has to be posted before the period is '
                .'closed. Use closeWithRevaluation() to do both in the right order.',
            );
        }

        $asAt ??= $period->end_date;

        $ledger = app(LedgerPosting::class);

        if ($ledger->hasJournalFor($period, 'revaluation')) {
            return null;
        }

        $adjustments = $this->adjustmentsFor($period, $asAt);

        if ($adjustments->isEmpty()) {
            return null;
        }

        $lines = [];
        $memo = 'Revaluation to the '.$asAt->toDateString().' closing rate';

        foreach ($adjustments as $adjustment) {
            $difference = $adjustment['difference'];
            $accountCode = $adjustment['account_code'];

            if ($adjustment['gain']) {
                $lines[] = [
                    'code' => $accountCode,
                    'debit' => $difference,
                    'memo' => $adjustment['currency'].' receivable/payable at closing rate',
                ];
                $lines[] = [
                    'code' => self::gainAccount(),
                    'credit' => $difference,
                    'memo' => 'Unrealised exchange gain',
                ];
            } else {
                $magnitude = Money::of(abs((float) $difference));

                $lines[] = [
                    'code' => self::lossAccount(),
                    'debit' => $magnitude,
                    'memo' => 'Unrealised exchange loss',
                ];
                $lines[] = [
                    'code' => $accountCode,
                    'credit' => $magnitude,
                    'memo' => $adjustment['currency'].' receivable/payable at closing rate',
                ];
            }
        }

        $entry = $ledger->post($period, $lines, $memo, $asAt->toDateString(), stage: 'revaluation');

        if ($entry) {
            Log::info('Revaluation posted for '.$period->label().': '.$adjustments->count().' adjustments.');
        }

        return $entry;
    }

    public static function gainAccount(): string
    {
        return '6600';
    }

    public static function lossAccount(): string
    {
        return '6610';
    }

    /**
     * The closing rate for a currency: the latest configured rate on or before
     * the date being valued.
     *
     * Returns null rather than defaulting, because a missing rate is a decision
     * for the user to make, not one to be guessed at close.
     */
    private function closingRateFor(string $currency, Carbon $asAt): ?float
    {
        $rate = ExchangeRate::latestFor($currency, $asAt->toDateString())?->rate_to_base;

        if ($rate === null || (float) $rate <= 0.0) {
            return null;
        }

        return (float) $rate;
    }
}