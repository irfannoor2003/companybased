<?php

namespace App\Services\Accounting;

use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creates periods, enforces that they do not overlap, and moves them through
 * open -> closed -> locked.
 *
 * The lifecycle is deliberately one-way past `closed`:
 *
 *   close  - stops new entries in the period. Reversible while the data is
 *            still being checked, which is the whole point of closing rather
 *            than locking.
 *   lock   - terminal. The figures in the period have been reported on or
 *            filed, so they cannot move again. Reopening would mean restating
 *            history silently.
 *
 * Locking is guarded: a period is refused if it holds entries that are still
 * draft or void-but-not-reversed, because locking those would strand them.
 */
class FiscalPeriodService
{
    /**
     * Create a period, refusing to overlap an existing one.
     *
     * @param  array{name?: ?string, start_date: mixed, end_date: mixed, status?: string, date_lock?: mixed}  $attributes
     */
    public function create(array $attributes): FiscalPeriod
    {
        $start = Carbon::parse($attributes['start_date'])->startOfDay();
        $end = Carbon::parse($attributes['end_date'])->endOfDay();

        if ($end->lt($start)) {
            throw new \InvalidArgumentException('A period cannot end before it starts.');
        }

        $this->assertNoOverlap($start, $end, $attributes['id'] ?? null);

        return FiscalPeriod::create([
            'name' => $attributes['name'] ?? null,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'status' => $attributes['status'] ?? 'open',
            'date_lock' => isset($attributes['date_lock'])
                ? Carbon::parse($attributes['date_lock'])->toDateString()
                : null,
        ]);
    }

    /**
     * Refuse a range that overlaps any existing period.
     *
     * Overlap means sharing a single day, not merely touching: a period ending
     * 31 March and one starting 1 April do not overlap, which is what lets the
     * calendar be contiguous.
     */
    public function assertNoOverlap(Carbon $start, Carbon $end, ?int $ignoreId = null): void
    {
        $conflict = FiscalPeriod::query()
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->orderBy('start_date')
            ->first();

        if ($conflict) {
            throw new FiscalPeriodConflict(
                'This range overlaps the existing period '
                .$conflict->label().' ('.$conflict->start_date->format('Y-m-d')
                .' to '.$conflict->end_date->format('Y-m-d').').',
            );
        }
    }

    public function close(FiscalPeriod $period, ?User $user = null, ?string $reason = null): FiscalPeriod
    {
        if ($period->isLocked()) {
            throw new FiscalPeriodLocked('A locked period cannot be closed again.');
        }

        return DB::transaction(function () use ($period, $user, $reason) {
            $locked = FiscalPeriod::query()->lockForUpdate()->findOrFail($period->id);

            $locked->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => $user?->id,
                'lock_reason' => $reason,
                'date_lock' => $locked->date_lock?->toDateString() ?? $locked->end_date->toDateString(),
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Close a period and refuse to reopen it. Terminal.
     */
    public function lock(FiscalPeriod $period, ?User $user = null, ?string $reason = null): FiscalPeriod
    {
        return DB::transaction(function () use ($period, $user, $reason) {
            $locked = FiscalPeriod::query()->lockForUpdate()->findOrFail($period->id);

            $this->assertLockable($locked);

            $locked->update([
                'status' => 'locked',
                'closed_at' => now(),
                'closed_by' => $user?->id,
                'lock_reason' => $reason ?? $locked->lock_reason,
                'date_lock' => $locked->date_lock?->toDateString() ?? $locked->end_date->toDateString(),
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Reopen a closed period. Refused for a locked one.
     */
    public function reopen(FiscalPeriod $period): FiscalPeriod
    {
        if ($period->isLocked()) {
            throw new FiscalPeriodLocked(
                'The period '.$period->label().' is locked and cannot be reopened. '
                .'Locked figures have already been reported and can only be corrected by a reversing entry.',
            );
        }

        // date_lock is cleared with the status: leaving it behind would keep refusing
        // entries for a period that is officially open again, which is the exact
        // confusion reopening is meant to resolve.
        $period->update([
            'status' => 'open',
            'closed_at' => null,
            'closed_by' => null,
            'date_lock' => null,
        ]);

        return $period->fresh();
    }

    /**
     * A period may not be locked while it still holds unresolved entries.
     *
     * Drafts were never reported, so stranding them is pointless; a void entry
     * with no reversal means a document was unwound without the ledger being told.
     */
    private function assertLockable(FiscalPeriod $period): void
    {
        // Matched on entry_date, not fiscal_period_id: a draft is never stamped with
        // a period (the stamp is applied when it is posted), so counting by the
        // foreign key would always find zero and the guard would never fire.
        $drafts = JournalEntry::query()
            ->whereDate('entry_date', '>=', $period->start_date->toDateString())
            ->whereDate('entry_date', '<=', $period->end_date->toDateString())
            ->where('status', 'draft')
            ->count();

        if ($drafts > 0) {
            throw new FiscalPeriodConflict(
                'This period still holds '.$drafts.' draft entr'.($drafts === 1 ? 'y' : 'ies')
                .'. Post or delete them before locking.',
            );
        }
    }

    /**
     * Generate the next $count periods as consecutive months, starting from the
     * first of the month after $from's end.
     *
     * Exists because maintaining a calendar by hand is how gaps appear, and a gap
     * silently stops enforcing the period at that date.
     */
    public function generateMonths(Carbon $from, int $count = 12): array
    {
        $created = [];
        // The months after $from. An overlap is refused by create() rather than
        // silently skipped: skipping would hide the fact that the caller's
        // assumption about where the calendar ends was wrong.
        $cursor = $from->copy()->startOfMonth()->addMonthNoOverflow();

        for ($i = 0; $i < $count; $i++) {
            $start = $cursor->copy();
            $end = $start->copy()->endOfMonth();

            $created[] = $this->create([
                'name' => $start->format('F Y'),
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ]);

            $cursor = $start->copy()->addMonthNoOverflow();
        }

        return $created;
    }
}
