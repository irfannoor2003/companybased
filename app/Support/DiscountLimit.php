<?php

namespace App\Support;

use App\Models\DiscountRule;
use Illuminate\Validation\ValidationException;

trait DiscountLimit
{
    /**
     * Get the maximum discount percentage allowed for the current user
     * based on active discount rules that apply to their role.
     */
    public function getMaxDiscountForUser(): ?float
    {
        $user = auth()->user();
        if (!$user) {
            return null;
        }

        $roles = $user->getRoleNames()->all();

        $rules = DiscountRule::active()
            ->where('type', 'percentage')
            ->get()
            ->filter(fn ($rule) => $rule->appliesToRole(...$roles));

        if ($rules->isEmpty()) {
            return null;
        }

        return $rules->min('max_value');
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
    public function validateDiscountLimits(array $items): void
    {
        $maxDiscount = $this->getMaxDiscountForUser();
        if ($maxDiscount === null) {
            return;
        }

        $maxDiscount = (float) $maxDiscount;

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

            if ($effectivePercent > $maxDiscount) {
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
