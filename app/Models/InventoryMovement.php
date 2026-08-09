<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InventoryMovement extends Model
{
    protected $fillable = [
        'item_id', 'warehouse_id', 'quantity_change', 'movement_type',
        'reference_type', 'reference_id', 'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity_change' => 'decimal:3',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(InventoryWarehouse::class, 'warehouse_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
