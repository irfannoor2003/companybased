<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayrollRun extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $auditModule = 'employees';

    protected $fillable = [
        'number', 'period_start', 'period_end', 'status', 'total_gross',
        'total_deductions', 'total_net', 'currency', 'exchange_rate', 'prepared_by', 'paid_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'total_gross' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'total_net' => 'decimal:2',

            /**
             * Held to 8 decimal places. A rate rounded to the money scale
             * misstates every converted figure derived from it, and the
             * error compounds across a whole payroll run.
             */
            'exchange_rate' => 'decimal:8',
            'paid_at' => 'datetime',
        ];
    }

    public static function statusOptions(): array
    {
        return ['draft', 'submitted', 'paid', 'void'];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where('number', 'like', "%{$term}%");
    }
}
