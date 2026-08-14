<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryIncomingShipmentItem extends Model
{
    protected $fillable = [
        'shipment_id', 'product_id', 'expected_quantity', 'received_quantity',
        'unit_cost', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'expected_quantity' => 'decimal:3',
            'received_quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(InventoryIncomingShipment::class, 'shipment_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'product_id', 'product_id');
    }
}
