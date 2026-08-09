<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesDeliveryNote extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $table = 'sales_delivery_notes';

    protected $fillable = [
        'number', 'order_id', 'customer_id', 'issue_date', 'status',
        'shipping_address', 'carrier', 'tracking_number', 'notes',
    ];

    protected $auditModule = 'sales';

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(SalesCustomer::class, 'customer_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesDeliveryNoteItem::class, 'delivery_note_id');
    }

    public function statusEvents(): MorphMany
    {
        return $this->morphMany(SalesStatusEvent::class, 'trackable');
    }

    public static function statusOptions(): array
    {
        return ['pending', 'packed', 'shipped', 'delivered'];
    }
}
