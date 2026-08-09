<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryWriteOffItem extends Model
{
    protected $fillable = [
        'write_off_id', 'item_id', 'quantity', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
        ];
    }

    public function writeOff(): BelongsTo
    {
        return $this->belongsTo(InventoryWriteOff::class, 'write_off_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }
}
