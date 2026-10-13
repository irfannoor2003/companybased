<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A work centre: a place work happens, and the thing that constrains it.
 *
 * A machine, a bench, a person, a line. The type is stated rather than implied by a
 * free-text name because it changes what the rate MEANS -- a machine hour and a
 * labour hour are different units, and conflating them is how a capacity plan
 * quietly becomes meaningless.
 *
 * ProductionAbsorptionService used to absorb overhead on direct labour hours
 * because, in its own words, "there is no work centre, machine or equipment record
 * anywhere in this application, so a machine-hour rate would have nothing to measure
 * against". That limitation is now removed rather than documented around.
 */
class InventoryWorkCentre extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'type', 'hourly_rate',
        'capacity_minutes_per_day', 'location', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'hourly_rate' => 'decimal:4',
            'capacity_minutes_per_day' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function bomOperations(): HasMany
    {
        return $this->hasMany(InventoryBomOperation::class, 'work_centre_id');
    }

    public function orderOperations(): HasMany
    {
        return $this->hasMany(InventoryProductionOrderOperation::class, 'work_centre_id');
    }

    /**
     * What the centre costs to run for a number of minutes.
     */
    public function costForMinutes(float $minutes): string
    {
        return \App\Support\Money::multiply((string) $this->hourly_rate, (string) ($minutes / 60));
    }

    /**
     * Whether this centre can absorb that much work on a day, and by when.
     *
     * Null capacity means unconstrained -- a bench someone works at as needed has no
     * daily limit worth pretending to have.
     *
     * Every operation due on the day counts, whatever its status. A COMPLETED
     * operation is excluded here in the obvious way, and that is wrong: the minutes
     * it took are exactly the minutes it consumed from the centre, and a job that
     * took 200 against a 60-minute day is the single most useful thing this report
     * can tell you. For a future date nothing is completed yet, so the distinction
     * does not arise.
     *
     * @return array{capacity: int|null, committed: float, remaining: float|null, over_capacity: bool}
     */
    public function capacityFor(\DateTimeInterface|string $date): array
    {
        $day = \Illuminate\Support\Carbon::parse($date)->toDateString();

        $committed = (float) InventoryProductionOrderOperation::query()
            ->where('work_centre_id', $this->id)
            ->whereHas('productionOrder', fn ($q) => $q->whereDate('scheduled_end_date', $day))
            ->selectRaw('COALESCE(SUM(GREATEST(minutes_spent, planned_minutes)), 0) AS minutes')
            ->value('minutes');

        $capacity = $this->capacity_minutes_per_day;

        return [
            'capacity' => $capacity,
            'committed' => round($committed, 2),
            'remaining' => $capacity === null ? null : round(max(0, (float) $capacity - $committed), 2),
            'over_capacity' => $capacity !== null && $committed > (float) $capacity,
        ];
    }

    public static function typeOptions(): array
    {
        return ['machine', 'labour', 'assembly', 'outsourced'];
    }
}