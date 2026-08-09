<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryWarehouse extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'name', 'code', 'address', 'is_active',
    ];

    protected $auditModule = 'inventory';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function stock(): HasMany
    {
        return $this->hasMany(InventoryStock::class, 'warehouse_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%");
        });
    }
}
