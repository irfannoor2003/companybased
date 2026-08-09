<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExpenseClaim extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $auditModule = 'accounting';

    protected $fillable = [
        'number', 'employee_name', 'expense_date', 'expense_type', 'merchant',
        'amount', 'currency', 'status', 'notes', 'reviewed_by', 'reviewed_at', 'reimbursed_at',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'reimbursed_at' => 'datetime',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('number', 'like', "%{$term}%")
                ->orWhere('employee_name', 'like', "%{$term}%")
                ->orWhere('merchant', 'like', "%{$term}%");
        });
    }

    public static function typeOptions(): array
    {
        return ['travel', 'meals', 'fuel', 'supplies', 'software', 'other'];
    }

    public static function statusOptions(): array
    {
        return ['pending', 'approved', 'rejected', 'reimbursed'];
    }
}