<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryProductionOrder extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'number', 'item_id', 'bill_of_material_id', 'warehouse_id', 'quantity',
        'scheduled_start_date', 'scheduled_end_date', 'status', 'note',
    ];

    protected $auditModule = 'inventory';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'scheduled_start_date' => 'date',
            'scheduled_end_date' => 'date',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function billOfMaterial(): BelongsTo
    {
        return $this->belongsTo(InventoryBillOfMaterial::class, 'bill_of_material_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(InventoryWarehouse::class, 'warehouse_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryProductionOrderItem::class, 'production_order_id');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where('number', 'like', "%{$term}%");
    }

    public static function statusOptions(): array
    {
        return ['draft', 'in_progress', 'completed', 'cancelled'];
    }
}
