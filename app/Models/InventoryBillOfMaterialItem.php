<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBillOfMaterialItem extends Model
{
    protected $fillable = [
        'bill_of_material_id', 'component_item_id', 'quantity', 'wastage_percent',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'wastage_percent' => 'decimal:2',
        ];
    }

    public function billOfMaterial(): BelongsTo
    {
        return $this->belongsTo(InventoryBillOfMaterial::class, 'bill_of_material_id');
    }

    public function componentItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'component_item_id');
    }
}
