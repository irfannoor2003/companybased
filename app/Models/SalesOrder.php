<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesOrder extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $table = 'sales_orders';

    protected $fillable = [
        'number', 'tracking_code', 'quote_id', 'customer_id', 'salesman_id', 'issue_date', 'expected_delivery_date',
        'status', 'currency', 'exchange_rate', 'subtotal', 'discount_amount', 'tax_amount', 'total',
        'shipping_address', 'notes',
    ];

    protected $auditModule = 'sales';

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'expected_delivery_date' => 'date',
            'exchange_rate' => 'decimal:6',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(SalesCustomer::class, 'customer_id');
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesman_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(SalesQuote::class, 'quote_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class, 'order_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class, 'order_id');
    }

    public function deliveryNotes(): HasMany
    {
        return $this->hasMany(SalesDeliveryNote::class, 'order_id');
    }

    public function statusEvents(): MorphMany
    {
        return $this->morphMany(SalesStatusEvent::class, 'trackable');
    }

    public static function statusOptions(): array
    {
        return ['draft', 'confirmed', 'packed', 'shipped', 'delivered', 'cancelled'];
    }

    public function isConfirmed(): bool
    {
        return ! in_array($this->status, ['draft', 'cancelled']);
    }
}
