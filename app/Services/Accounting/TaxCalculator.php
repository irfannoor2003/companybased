<?php

namespace App\Services\Accounting;

use App\Models\TaxRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use App\Support\Money;

/**
 * Works out the tax on a document from the rate catalogue.
 *
 * The application used to have no notion of a tax rate at all: every line carried
 * a hand-typed `tax_percent`. Nothing validated it, two rates could apply to the
 * same goods on the same day, and changing the rate meant editing documents
 * already issued.
 *
 * This service resolves the rate in force for a date, and computes tax on it.
 * Documents keep their snapshot percentage, so a document already issued is never
 * restated by a later rate change -- which is the whole reason the resolution
 * takes a date.
 *
 * The base is always the line NET, after discount. Tax on the gross would charge
 * tax on money that was never received.
 */
class TaxCalculator
{
    /**
     * The rate that applies to a document kind on a date, if any.
     *
     * Returns null when nothing is configured. That is deliberately distinct from a
     * zero rate: "no rate is set up" is an administrative gap to report, whereas
     * zero means the goods are exempt and belong in the exempt box on the return.
     */
    public function rateFor(string $appliesTo, mixed $date = null): ?TaxRate
    {
        // 'both' is a convenience for a rate that genuinely applies either way.
        if ($appliesTo === 'sales' || $appliesTo === 'purchase') {
            $specific = TaxRate::query()
                ->active()
                ->inForceOn($appliesTo, $date)
                ->orderByDesc('priority')
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->first();

            if ($specific) {
                return $specific;
            }
        }

        return TaxRate::query()
            ->active()
            ->inForceOn('both', $date)
            ->orderByDesc('priority')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Tax on a net amount under the rate in force on a date.
     *
     * @return array{tax: string, rate: string, rate_id: int|null, exempt: bool, reason: string|null}
     */
    public function onAmount(string|float $net, string $appliesTo = 'sales', mixed $date = null): array
    {
        $net = Money::of($net);
        $rate = $this->rateFor($appliesTo, $date);

        if ($rate === null) {
            return [
                'tax' => '0.00',
                'rate' => '0.0000',
                'rate_id' => null,
                'exempt' => false,
                'reason' => 'No tax rate is configured for '.$appliesTo.' documents, so no tax was charged.',
            ];
        }

        if ($rate->is_exempt) {
            return [
                'tax' => '0.00',
                'rate' => $rate->rate,
                'rate_id' => $rate->id,
                'exempt' => true,
                'reason' => 'Rate '.$rate->code.' is marked exempt.',
            ];
        }

        if ($rate->isBelowThreshold($net)) {
            return [
                'tax' => '0.00',
                'rate' => $rate->rate,
                'rate_id' => $rate->id,
                'exempt' => true,
                'reason' => 'Below the '.$rate->code.' threshold of '
                    .Money::of($rate->threshold_amount).'.',
            ];
        }

        return [
            'tax' => $rate->taxOn($net),
            'rate' => $rate->rate,
            'rate_id' => $rate->id,
            'exempt' => false,
            'reason' => null,
        ];
    }

/**
     * Every rate in force for a document kind on a date, not just the winner.
     *
     * `rateFor()` deliberately returns a single rate, because a document needs one
     * figure to charge. That is the wrong question for *validation*: a business
     * that legitimately charges 20% on standard goods and 10% on reduced goods has
     * two rates in force on the same day, and a line at either one is correct.
     * Validating against only the winner would refuse the non-winning rate, which
     * is how a legitimate document gets blocked.
     *
     * Priority still decides the *default* shown in the editor -- the most specific
     * rate is the sensible suggestion -- but it must not decide which rates are
     * considered valid.
     *
     * @return \Illuminate\Support\Collection<int, TaxRate>
     */
    public function ratesInForce(string $appliesTo, mixed $date = null): Collection
    {
        if ($appliesTo === 'sales' || $appliesTo === 'purchase') {
            $specific = TaxRate::query()
                ->active()
                ->inForceOn($appliesTo, $date)
                ->get();

            if ($specific->isNotEmpty()) {
                return $specific;
            }
        }

        return TaxRate::query()
            ->active()
            ->inForceOn('both', $date)
            ->get();
    }

    /**
     * Validate the tax percentages a document was submitted with, against the
     * rate catalogue.
     *
     * Rates used to be hand-typed on every line with nothing checking them, so a
     * document could be issued at any rate the typist cared to enter -- including
     * a rate no longer in force, or one that does not exist. This reports the
     * difference so the caller can refuse or warn.
     *
     * @param  array<int, float|string>  $percents  the tax_percent of each line
     * @return array<int, array{index: int, submitted: string, expected: string, reason: string}>
     */
    public function validatePercents(array $percents, string $appliesTo = 'sales', mixed $date = null): array
    {
        $rate = $this->rateFor($appliesTo, $date);
        $candidates = $this->ratesInForce($appliesTo, $date);
        $problems = [];

        foreach ($percents as $index => $submitted) {
            $submitted = Money::of($submitted);

            if ($rate === null) {
                /**
                 * No rate configured, but a non-zero figure was typed anyway.
                 *
                 * This is the dangerous combination: the document would charge tax
                 * that no rate supports, so the collected amount cannot be
                 * reconciled against anything on the return.
                 */
                if (Money::isGreaterThan($submitted, '0')) {
                    $problems[] = [
                        'index' => $index,
                        'submitted' => $submitted,
                        'expected' => '0.00',
                        'reason' => 'No tax rate is configured, but this line charges '
                            .$submitted.' of tax.',
                    ];
                }

                continue;
            }

if ($rate->is_exempt) {
                // Only exempt if NOTHING else in force would explain the charge.
                // A business with one exempt rate and one taxable rate in force the
                // same day must still be able to issue a document at the taxable one.
                $matchedOther = $candidates->contains(
                    fn (TaxRate $candidate) => ! $candidate->is_exempt
                        && Money::equals($submitted, (string) $candidate->rate)
                );

                if (! $matchedOther && Money::isGreaterThan($submitted, '0')) {
                    $problems[] = [
                        'index' => $index,
                        'submitted' => $submitted,
                        'expected' => '0.00',
                        'reason' => 'Rate '.$rate->code.' is marked exempt, but this line charges tax.',
                    ];
                }

                continue;
            }

/**
             * Whether the submitted percentage matches any rate in force that day.
             *
             * `rateFor()` deliberately returns one rate, because a document needs one
             * figure to charge. That is the wrong question for validation: a
             * business charging 20% on standard goods and 10% on reduced goods has
             * two rates in force on the same day, and a line at either is correct.
             * Validating against only the winner refuses the non-winning rate, which
             * is how a legitimate document gets blocked.
             *
             * Compared to four places, not two. Rates are stored to four places
             * because a rate such as 16.6667% rounds to 16.67 at two, which puts the
             * tax on every line out of step with the declared rate. Comparing at
             * money scale would then reject a perfectly correct line.
             */
            $matches = $candidates->contains(
                fn (TaxRate $candidate) => ! $candidate->is_exempt
                    && Money::equals($submitted, (string) $candidate->rate)
            );

            if (! $matches) {
                $inForce = $candidates->map(fn (TaxRate $candidate) => $candidate->label())
                    ->filter()
                    ->join(', ');

                $problems[] = [
                    'index' => $index,
                    'submitted' => $submitted,
                    // The rate the editor would have suggested, so the caller can
                    // report what was expected even though any in-force rate is valid.
                    'expected' => (string) $rate->rate,
                    'reason' => 'Line '.($index + 1).' charges '.$submitted.'% but no rate in force on '
                        .($date ? Carbon::parse($date)->toDateString() : 'today')
                        .' matches it. Rates in force: '
                        .($inForce !== '' ? $inForce : 'none')
                        .'. The rate in force is '.(string) $rate->rate.'%.',
                ];
            }
        }

        return $problems;
    }

    /**
     * The percentage to stamp on a document line so it is charged correctly.
     *
     * This is the effective percentage, not the declared rate. Compound tax and
     * thresholds both mean the figure charged on the line differs from the rate on
     * the catalogue, and DocumentItems computes from the line percentage -- so the
     * effective figure is what has to be written down or the totals will not match
     * the return.
     */
    public function effectivePercentFor(string|float $net, string $appliesTo = 'sales', mixed $date = null): string
    {
        $net = Money::of($net);
        $result = $this->onAmount($net, $appliesTo, $date);

        // A zero net cannot produce a meaningful percentage, and dividing by the
        // rate to recover it would divide by zero where the rate itself is zero.
        if (Money::equals($net, '0')) {
            return '0.0000';
        }

        // Derived from the tax actually charged rather than restated from the
        // declared rate, so a compound or threshold regime is reflected in the
        // figure written onto the line.
        return number_format(
            (float) $result['tax'] * 100 / (float) $net,
            4,
            '.',
            '',
        );
    }
}