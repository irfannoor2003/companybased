<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryProductionOrderItem extends Model
{
    protected $fillable = [
        'production_order_id', 'component_item_id', 'quantity_required', 'quantity_used',
    ];

    protected function casts(): array
    {
        return [
            'quantity_required' => 'decimal:3',
            'quantity_used' => 'decimal:3',
        ];
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(InventoryProductionOrder::class, 'production_order_id');
    }

    public function componentItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'component_item_id');
    }
}
