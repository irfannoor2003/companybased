<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An accounting period, and the gate on when a journal entry may be posted.
 *
 * Two things are modelled, because they are genuinely different:
 *
 *  - status: open, closed, locked. Locked is terminal -- it cannot be reopened,
 *    because a locked period means the numbers in it have already been reported
 *    on and possibly filed.
 *  - date_lock: set independently of status. A period can still be open for new
 *    work while refusing backdated entries into dates already reported. This is
 *    how real ledgers behave, and conflating the two is what makes "we closed the
 *    books" ambiguous.
 *
 * Periods are contiguous and non-overlapping. Overlap is prevented in the database
 * as well as here, because two periods covering the same date would make
 * "which period is this date in" return an arbitrary answer.
 */
class FiscalPeriod extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'name', 'start_date', 'end_date', 'status', 'date_lock',
        'closed_at', 'closed_by', 'lock_reason',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'date_lock' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public function journalEntries()
    {
        return $this->hasMany(JournalEntry::class, 'fiscal_period_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isLocked(): bool
    {
        return $this->status === 'locked';
    }

    public function contains(mixed $date): bool
    {
        $date = $date instanceof \DateTimeInterface ? Carbon::instance($date) : Carbon::parse((string) $date);

        return $date->betweenIncluded(
            $this->start_date->startOfDay(),
            $this->end_date->endOfDay(),
        );
    }

    /**
     * Whether a journal dated $date may be posted given this period's state.
     *
     * Returns a reason string instead of a bool so the caller can explain the
     * refusal. A period that does not contain the date is not this period's
     * business -- there is nothing to refuse.
     */
    public function postingRefusal(mixed $date): ?string
    {
        if (! $this->contains($date)) {
            return null;
        }

        if ($this->isLocked()) {
            return 'The period '.$this->label().' is locked. A locked period cannot be reopened.';
        }

        if ($this->date_lock && Carbon::parse($date)->startOfDay()->lte($this->date_lock)) {
            return $this->status === 'closed' || $this->status === 'locked'
                ? 'The period '.$this->label().' is closed. Entries dated on or before '
                    .$this->date_lock->format('Y-m-d').' are refused.'
                : 'Entries dated on or before '.$this->date_lock->format('Y-m-d')
                    .' are refused because the period is locked to backdating.';
        }

        return null;
    }

    public function label(): string
    {
        return $this->name ?: ($this->start_date?->format('M Y').' - '.$this->end_date?->format('M Y'));
    }

    /**
     * The period a date falls in, if one is defined.
     *
     * Order is explicit rather than relying on insertion order, because a
     * backdated entry must resolve to the earliest period that covers it.
     */
    public static function forDate(mixed $date): ?self
    {
        $date = $date instanceof \DateTimeInterface
            ? Carbon::instance($date)
            : Carbon::parse((string) $date);

        return static::query()
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->orderBy('start_date')
            ->first();
    }

    /**
     * Assert a journal may be posted on $date.
     *
     * Dates with no period defined are allowed. This is deliberate: requiring a
     * period to exist before anything can post would make installing the ledger
     * a prerequisite for trading, and a gap in the calendar is far more likely to
     * be an oversight than an attempt to post somewhere forbidden.
     */
    public static function assertPostable(mixed $date): void
    {
        $period = static::forDate($date);

        if (! $period) {
            return;
        }

        if ($refusal = $period->postingRefusal($date)) {
            throw new \App\Services\Accounting\FiscalPeriodClosed($refusal);
        }
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public static function statusOptions(): array
    {
        return ['open', 'closed', 'locked'];
    }
}
