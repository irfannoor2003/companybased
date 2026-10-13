<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryBillOfMaterial extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'name', 'item_id', 'version', 'status', 'note',
    ];

    protected $auditModule = 'inventory';

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryBillOfMaterialItem::class, 'bill_of_material_id');
    }

    /**
     * The routing: which work centres make this, in what order.
     *
     * Held on the bill of materials rather than the product, because the operations
     * belong to the recipe. A separate routing table would let a product end up with
     * a bill of materials describing its parts and a routing describing a process
     * nobody can make it by.
     */
    public function operations(): HasMany
    {
        return $this->hasMany(InventoryBomOperation::class, 'bill_of_material_id');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where('name', 'like', "%{$term}%");
    }

    public static function statusOptions(): array
    {
        return ['active', 'inactive'];
    }
}
