<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A tax rate in force over a date range.
 *
 * Rates are effective dated rather than edited in place: changing a rate creates
 * a new row so a document issued last month still resolves to last month's rate.
 * Editing a rate in place would silently restate documents already issued, which
 * is how a company ends up charging two different rates for the same goods in the
 * same month.
 */
class TaxRate extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $auditModule = 'accounting';

    protected $fillable = [
        'code', 'name', 'rate', 'applies_to', 'account_id',
        'effective_from', 'effective_to', 'priority',
        'is_compound', 'threshold_amount', 'is_exempt', 'is_active',
        'currency', 'notes',
    ];

    protected function casts(): array
    {
        return [
            // Four places: a rate such as 16.6667% rounds to 16.67% at two, which
            // puts collected tax permanently out of step with the declared rate.
            'rate' => 'decimal:4',
            'is_compound' => 'boolean',
            'is_exempt' => 'boolean',
            'is_active' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'threshold_amount' => 'decimal:2',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /**
     * Rates usable for a given document kind and date.
     *
     * @param  'sales'|'purchase'  $appliesTo
     */
    public function scopeInForceOn(Builder $query, string $appliesTo, mixed $date = null): Builder
    {
        $date = $date ? Carbon::parse($date)->toDateString() : Carbon::now()->toDateString();

        return $query->where('applies_to', $appliesTo)
            ->whereDate('effective_from', '<=', $date)
            ->where(function (Builder $q) use ($date) {
                // No end date means still current.
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date);
            });

        // Ordered by priority so the caller can take the first, and by start date
        // descending so the most recently introduced rate wins a tie.
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The rate that applies to a document of this kind on a given date.
     *
     * Returns null rather than a zero rate when nothing is configured. A caller
     * that receives null knows it has a missing rate; a caller handed 0 assumes the
     * goods are exempt. Those are very different situations on a tax return.
     */
    public static function resolve(string $appliesTo, mixed $date = null): ?self
    {
        return static::query()
            ->active()
            ->inForceOn($appliesTo, $date)
            ->orderByDesc('priority')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Whether this rate is in force on a date.
     */
    public function isInForceOn(mixed $date): bool
    {
        $date = Carbon::parse($date);

        if ($date->lt($this->effective_from->startOfDay())) {
            return false;
        }

        return $this->effective_to === null
            || $date->lte($this->effective_to->endOfDay());
    }

    /**
     * Close off this rate at the end of a date, so a successor can take over.
     *
     * Used when a rate is replaced: the new row opens the following day and this
     * one is closed the day before, leaving no window where nothing applies.
     */
    public function supersededFrom(mixed $date): self
    {
        $this->effective_to = Carbon::parse($date)->toDateString();
        $this->save();

        return $this;
    }

    /**
     * Tax on a net amount.
     *
     * @param  string|float  $net  the amount tax is charged on, after any discount
     */
    public function taxOn(string|float $net): string
    {
        if ($this->is_exempt) {
            return '0.00';
        }

        $net = Money::of($net);
        $rate = (string) $this->rate;

        if ($this->is_compound) {
            // Tax charged on the tax too.
            //
            // The gross is net / (1 - rate), so the tax is that gross less the
            // net. For 100 at 20% the gross is 125 and the tax 25, which is
            // exactly 20% of what the customer actually paid.
            //
            // The order of the subtraction matters and is easy to invert: it is
            // gross MINUS net. Written the other way round it yields a negative
            // tax, which then flows onto documents as a credit.
            //
            // The rate is stored as a PERCENTAGE (20.0000 means 20%), so it has to
            // be converted to a fraction before it can be subtracted from 1.
            // Using it directly gives 1 - 20 = -19, which then divides out a
            // nonsense gross and produces a large negative tax.
            $fraction = Money::divide($rate, '100');
            $gross = Money::divide($net, Money::subtract('1', $fraction));

            return Money::of(Money::subtract($gross, $net));
        }

        return Money::percentageOf($net, $rate);
    }

    /**
     * Whether a net amount falls below this rate's threshold.
     *
     * Null threshold means no threshold, so nothing is exempt.
     */
    public function isBelowThreshold(string|float $net): bool
    {
        return $this->threshold_amount !== null
            && Money::isLessThan(Money::of($net), Money::of($this->threshold_amount));
    }

    public function label(): string
    {
        return rtrim(rtrim(number_format((float) $this->rate, 4, '.', ''), '0'), '.').'%';
    }

    public static function appliesToOptions(): array
    {
        return ['sales', 'purchase', 'both'];
    }
}