<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaxReturn extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $auditModule = 'accounting';

    protected $fillable = [
        'number', 'tax_type', 'period_label', 'period_start', 'period_end',
        'gross_receipts', 'taxable_amount', 'tax_collected', 'tax_credits', 'tax_due',
        'status', 'filed_at', 'paid_at', 'currency', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'gross_receipts' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'tax_collected' => 'decimal:2',
            'tax_credits' => 'decimal:2',
            'tax_due' => 'decimal:2',
            'filed_at' => 'date',
            'paid_at' => 'date',
        ];
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('number', 'like', "%{$term}%")
                ->orWhere('period_label', 'like', "%{$term}%");
        });
    }

    public static function typeOptions(): array
    {
        return ['sales', 'income', 'withholding', 'other'];
    }

    public static function statusOptions(): array
    {
        return ['draft', 'filed', 'paid'];
    }
}