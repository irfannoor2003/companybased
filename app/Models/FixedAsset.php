<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class FixedAsset extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $auditModule = 'fixed_assets';

    protected $fillable = [
        'asset_code', 'name', 'category', 'purchase_date', 'purchase_cost',
        'salvage_value', 'useful_life_months', 'depreciation_method',
        'depreciation_rate', 'location', 'department', 'serial_number',
        'supplier', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'purchase_cost' => 'decimal:2',
            'salvage_value' => 'decimal:2',
            'depreciation_rate' => 'decimal:4',
        ];
    }

    public function depreciations(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciation::class, 'fixed_asset_id');
    }

    public function disposal(): HasOne
    {
        return $this->hasOne(FixedAssetDisposal::class, 'fixed_asset_id');
    }

    public static function statusOptions(): array
    {
        return ['in_use', 'stored', 'disposed'];
    }

    public static function methodOptions(): array
    {
        return ['straight_line', 'reducing_balance'];
    }

    public function accumulatedDepreciation(): float
    {
        return round((float) $this->depreciations()->sum('amount'), 2);
    }

    public function bookValue(): float
    {
        return round((float) $this->purchase_cost - $this->accumulatedDepreciation(), 2);
    }

    public function monthlyDepreciation(): float
    {
        if ($this->useful_life_months <= 0) {
            return 0.0;
        }

        if ($this->depreciation_method === 'reducing_balance') {
            $rate = (float) ($this->depreciation_rate ?? 0);

            return round($this->bookValue() * ($rate / 100), 2);
        }

        $depreciable = (float) $this->purchase_cost - (float) $this->salvage_value;

        return round(max($depreciable, 0) / $this->useful_life_months, 2);
    }

    public function isFullyDepreciated(): bool
    {
        return $this->bookValue() <= (float) $this->salvage_value;
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('asset_code', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%")
                ->orWhere('category', 'like', "%{$term}%")
                ->orWhere('serial_number', 'like', "%{$term}%");
        });
    }
}