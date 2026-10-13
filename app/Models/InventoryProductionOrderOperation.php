<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An operation on a production order: the routing as it was RELEASED, plus the time
 * it actually took.
 *
 * The routing details are copied rather than referenced. A production order must
 * keep showing the route it was released against: editing a BOM after a job has
 * started must not silently retime work that is already half done, and the only way
 * to guarantee that is for the order to own its own copy.
 *
 * `minutes_spent` is what makes the standard time a comparison rather than an
 * assumption. Without it, a routing says what a job should take and nothing records
 * what it did take, so there is no way to know which routes need re-timing -- and no
 * basis at all for absorbing overhead on machine hours.
 */
class InventoryProductionOrderOperation extends Model
{
    protected $fillable = [
        'production_order_id', 'bom_operation_id', 'sequence', 'name', 'work_centre_id',
        'setup_minutes', 'run_minutes_per_unit', 'planned_minutes', 'minutes_spent',
        'status', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'setup_minutes' => 'decimal:2',
            'run_minutes_per_unit' => 'decimal:4',
            'planned_minutes' => 'decimal:2',
            'minutes_spent' => 'decimal:2',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(InventoryProductionOrder::class);
    }

    public function workCentre(): BelongsTo
    {
        return $this->belongsTo(InventoryWorkCentre::class);
    }

    public function bomOperation(): BelongsTo
    {
        return $this->belongsTo(InventoryBomOperation::class);
    }

    /**
     * Planned minus actual, in minutes. Positive means the operation ran fast.
     *
     * Null until the operation is reported, which is different from zero: an
     * operation not yet done has no variance, and reporting 0 minutes for it would
     * claim it was infinitely quick.
     */
    public function varianceMinutes(): ?float
    {
        if ((float) $this->minutes_spent <= 0) {
            return null;
        }

        return round((float) $this->planned_minutes - (float) $this->minutes_spent, 2);
    }

    public function isReported(): bool
    {
        return (float) $this->minutes_spent > 0;
    }

    public static function statusOptions(): array
    {
        return ['pending', 'running', 'complete'];
    }
}