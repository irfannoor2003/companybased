<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttendanceRecord extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $auditModule = 'employees';

    protected $fillable = [
        'employee_id', 'attendance_date', 'check_in_at', 'check_out_at', 'method',
        'status', 'work_minutes', 'shift_start', 'shift_end', 'grace_minutes',
        'is_weekend', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'is_weekend' => 'boolean',
        ];
    }

    public static function statusOptions(): array
    {
        return ['pending', 'present', 'late', 'short_leave', 'half_day', 'absent'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * Restrict to records falling inside an inclusive date range.
     *
     * Uses whereDate() rather than a bare >= / <= string comparison. On SQLite
     * the model casts attendance_date to `date`, which round-trips as
     * '2026-09-11 00:00:00'; that string is greater than '2026-09-11', so `<=`
     * dropped the last day of the period. MySQL stores a bare '2026-09-11' and
     * was never affected, so this was a test-database artefact rather than a
     * production bug — but whereDate() is the correct expression of an
     * inclusive date range on both drivers and is kept for that reason.
     */
    public function scopeForPeriod(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn ($q) => $q->whereDate('attendance_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('attendance_date', '<=', $to));
    }
}
