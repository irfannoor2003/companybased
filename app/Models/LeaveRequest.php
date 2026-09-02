<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    use Auditable;

    protected $auditModule = 'leave_requests';

    protected $fillable = [
        'employee_id', 'leave_type', 'start_date', 'end_date', 'days',
        'reason', 'status', 'reviewed_by', 'reviewed_at', 'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'reviewed_at' => 'datetime',
            'days' => 'integer',
        ];
    }

    public static function typeOptions(): array
    {
        return ['annual', 'sick', 'casual', 'unpaid', 'maternity', 'paternity', 'study', 'other'];
    }

    public static function statusOptions(): array
    {
        return ['pending', 'approved', 'rejected', 'cancelled'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Number of inclusive calendar days covered by the request.
     */
    public static function coveredDays(Carbon|string $start, Carbon|string $end): int
    {
        $startDate = $start instanceof Carbon ? $start : Carbon::parse($start);
        $endDate = $end instanceof Carbon ? $end : Carbon::parse($end);

        return max(1, $startDate->diffInDays($endDate) + 1);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->whereHas('employee', function (Builder $q) use ($term) {
            $q->where('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")
                ->orWhere('employee_code', 'like', "%{$term}%");
        })->orWhere('leave_type', 'like', "%{$term}%");
    }
}