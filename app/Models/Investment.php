<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Investment extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $auditModule = 'investments';

    protected $fillable = [
        'code', 'name', 'type', 'institution', 'purchase_date', 'quantity',
        'unit_cost', 'total_cost', 'current_price', 'current_value',
        'currency', 'maturity_date', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'maturity_date' => 'date',
            'quantity' => 'decimal:6',
            'unit_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'current_price' => 'decimal:2',
            'current_value' => 'decimal:2',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(InvestmentTransaction::class);
    }

    public function dividends(): HasMany
    {
        return $this->hasMany(InvestmentDividend::class);
    }

    public static function typeOptions(): array
    {
        return ['stock', 'bond', 'treasury_bill', 'mutual_fund', 'etf', 'real_estate', 'crypto', 'other'];
    }

    public static function statusOptions(): array
    {
        return ['active', 'matured', 'sold'];
    }

    public function marketValue(): float
    {
        if ($this->current_value !== null) {
            return (float) $this->current_value;
        }

        if ($this->current_price !== null) {
            return round((float) $this->quantity * (float) $this->current_price, 2);
        }

        return (float) $this->total_cost;
    }

    public function gainLoss(): float
    {
        return round($this->marketValue() - (float) $this->total_cost, 2);
    }

    public function returnPct(): float
    {
        $cost = (float) $this->total_cost;

        return $cost > 0 ? round(($this->gainLoss() / $cost) * 100, 2) : 0.0;
    }

    public function totalDividends(): float
    {
        return round((float) $this->dividends()->sum('amount'), 2);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('code', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%")
                ->orWhere('institution', 'like', "%{$term}%")
                ->orWhere('notes', 'like', "%{$term}%");
        });
    }
}