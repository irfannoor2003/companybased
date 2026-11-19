<?php

namespace App\Support;

use App\Models\DiscountRule;
use Illuminate\Validation\ValidationException;

trait DiscountLimit
{
    /**
     * The active rules that constrain the current user.
     *
     * An admin is bound by every active rule, not only by the ones that name
     * their role. A rule set up as "for all" lists the three staff roles, but
     * an administrator creating an invoice is just as capable of giving away
     * the company's margin. They can still change the rule itself in Settings;
     * they just cannot quietly exceed it on a document.
     *
     * @return \Illuminate\Support\Collection<int,DiscountRule>
     */
    private function applicableRules(): \Illuminate\Support\Collection
    {
        $user = auth()->user();

        if (! $user) {
            return collect();
        }

        $roles = $user->getRoleNames()->all();
        $privileged = count(array_intersect($roles, ['Admin', 'Super Admin'])) > 0;

        return DiscountRule::active()->get()->filter(
            fn (DiscountRule $rule) => $privileged || $rule->appliesToRoles($roles),
        );
    }

    /**
     * Get the maximum discount percentage allowed for the current user,
     * for prefilling the line-item editor's client-side clamp.
     *
     * Fixed-amount rules cannot be expressed as one percentage without a line
     * total, so they are enforced by validateDiscountLimits() alone. Returning
     * null here does not mean "unrestricted" -- it means no percentage cap to
     * prefill. The server remains authoritative.
     */
    public function getMaxDiscountForUser(): ?float
    {
        $caps = $this->applicableRules()
            ->where('type', 'percentage')
            ->pluck('max_value');

        return $caps->isEmpty() ? null : (float) $caps->min();
    }

    /**
     * The tightest effective cap for one line, as a percentage.
     *
     * A fixed rule's ceiling depends on the line's gross: $100 off a $1,000
     * line is 10%, but 100% off a $100 line. A fixed rule applies only when it
     * has no currency or its currency matches the document's, since comparing
     * 100 PKR against a USD invoice would be meaningless.
     */
    private function capForLine(float $gross, ?string $currency): ?float
    {
        $caps = [];

        foreach ($this->applicableRules() as $rule) {
            if ($rule->type === 'percentage') {
                $caps[] = (float) $rule->max_value;

                continue;
            }

            if ($rule->currency && $currency && strcasecmp((string) $rule->currency, $currency) !== 0) {
                continue;
            }

            if ($gross > 0.0) {
                $caps[] = round(((float) $rule->max_value / $gross) * 100, 2);
            }
        }

        return $caps === [] ? null : min($caps);
    }

    /**
     * Validate that all item discounts are within the allowed limit.
     *
     * Every call site is a browser form post (invoice, quote, order, credit note),
     * so this must raise a ValidationException. It used to abort() with a JSON
     * body, which handed the browser a raw JSON blob instead of a redirect back to
     * the form, so the user saw the error text with no way to edit the line and
     * no way back. The message now goes into the normal $errors bag so the form
     * redisplays with the offending line highlighted.
     */
    public function validateDiscountLimits(array $items, ?string $currency = null): void
    {
        if ($this->applicableRules()->isEmpty()) {
            return;
        }

        foreach ($items as $index => $item) {
            // A line may discount by percentage or by a specific amount off the
            // line, so the cap has to be tested against whichever is in play.
            // A fixed amount is a percentage of that line's gross once it is
            // priced, and is compared the same way a percentage discount is.
            $discountAmount = (float) ($item['discount_amount'] ?? 0);
            $discountPercent = (float) ($item['discount_percent'] ?? 0);
            $gross = round((float) ($item['qty'] ?? 1) * (float) ($item['unit_price'] ?? 0), 2);

            $effectivePercent = $discountAmount > 0.0
                ? round($gross > 0.0 ? ($discountAmount / $gross) * 100 : 0.0, 2)
                : $discountPercent;

            $maxDiscount = $this->capForLine($gross, $currency);

            if ($maxDiscount !== null && $effectivePercent > $maxDiscount) {
                $message = sprintf(
                    'Discount on line %d (%s%%) exceeds your maximum allowed discount (%s%%).',
                    $index + 1,
                    $this->trimNumber($effectivePercent),
                    $this->trimNumber($maxDiscount),
                );

                // Both keys are needed. The line-item editor renders only the
                // aggregate `items` key, and Laravel's has() does not match a
                // dotted child against a flat message bag, so the specific key
                // alone would be collected but never shown. The dotted key is
                // kept for per-field rendering and for callers that inspect it.
                throw ValidationException::withMessages([
                    'items' => $message,
                    "items.{$index}.discount_percent" => $message,
                    "items.{$index}.discount_amount" => $message,
                ]);
            }
        }
    }

    /**
     * The tax percentage the engine expects on a document, for prefilling.
     *
     * The line editor is a plain number input with no knowledge of the catalogue,
     * so without this the user has to know the rate and type it in on every
     * document. Returns null when no rate applies, which the caller should show as
     * an explicit "no rate configured" rather than as a zero -- a zero rate and a
     * missing rate mean different things on a return.
     *
     * @return array{percent: string|null, rate_name: string|null, reason: string|null}
     */
    public function taxRateHint(string $appliesTo = 'sales', mixed $date = null): array
    {
        $rate = app(\App\Services\Accounting\TaxCalculator::class)->rateFor($appliesTo, $date);

        if ($rate === null) {
            return [
                'percent' => null,
                'rate_name' => null,
                'reason' => 'No tax rate is configured for '.$appliesTo.' documents on '
                    .($date ? \Illuminate\Support\Carbon::parse($date)->toDateString() : 'today')
                    .'. Tax will not be charged until one is set.',
            ];
        }

        return [
            'percent' => (string) $rate->rate,
            'rate_name' => $rate->name,
            'reason' => $rate->is_exempt
                ? $rate->name.' is marked exempt.'
                : ($rate->is_compound
                    ? $rate->name.' is charged on the tax as well as the net.'
                    : null),
        ];
    }

    /**
     * Refuse tax percentages that do not match the rate in force.
     *
     * The tax engine resolves a rate for each document date, but the line editor
     * still submits a hand-typed percentage -- so without this check a document
     * could be issued at any rate the typist chose, including one that is no
     * longer in force or does not exist at all. The collected tax would then
     * reconcile against nothing on the return.
     *
     * Only enforces when a rate is configured AND the user holds the tax
     * permission. Without a rate configured there is nothing to validate against,
     * and silently blocking every invoice would make the engine unusable until the
     * catalogue is filled in -- a worse failure than a visible warning.
     *
     * @param  string  $appliesTo  'sales' or 'purchase'
     * @param  mixed  $date  the document's own date
     */
    public function validateTaxRates(
        array $items,
        string $appliesTo = 'sales',
        mixed $date = null,
    ): void {
        if (! auth()->user()?->can('accounting.tax_rates.view')) {
            return;
        }

        $percents = array_map(
            fn ($item) => (float) ($item['tax_percent'] ?? 0),
            $items,
        );

        $problems = app(\App\Services\Accounting\TaxCalculator::class)
            ->validatePercents($percents, $appliesTo, $date);

        if ($problems === []) {
            return;
        }

        $index = $problems[0]['index'];

        throw ValidationException::withMessages([
            'items' => $problems[0]['reason'],
            "items.{$index}.tax_percent" => $problems[0]['reason'],
        ]);
    }

    /**
     * Render a percentage without a trailing ".00".
     */
    private function trimNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
