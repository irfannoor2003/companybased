<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesDeliveryNoteItem extends Model
{
    protected $table = 'sales_delivery_note_items';

    protected $fillable = [
        'delivery_note_id', 'product_id', 'description', 'qty',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
        ];
    }

    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(SalesDeliveryNote::class, 'delivery_note_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
