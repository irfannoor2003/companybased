<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryItem extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'product_id', 'reorder_level', 'reorder_quantity', 'notes', 'is_active',
    ];

    protected $auditModule = 'inventory';

    protected function casts(): array
    {
        return [
            'reorder_level' => 'decimal:3',
            'reorder_quantity' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function stock(): HasMany
    {
        return $this->hasMany(InventoryStock::class, 'item_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'item_id');
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

        return $query->whereHas('product', fn (Builder $q) => $q
            ->where('name', 'like', "%{$term}%")
            ->orWhere('sku', 'like', "%{$term}%")
            ->orWhere('barcode', 'like', "%{$term}%"));
    }
}
