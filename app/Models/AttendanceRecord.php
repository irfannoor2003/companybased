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

    public function scopeForPeriod(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn ($q) => $q->where('attendance_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('attendance_date', '<=', $to));
    }
}
