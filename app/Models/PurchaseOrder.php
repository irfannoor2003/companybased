<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'number', 'quote_id', 'supplier_id', 'warehouse_id', 'order_date',
        'expected_delivery_date', 'status', 'currency', 'subtotal', 'discount_amount',
        'tax_amount', 'total', 'shipping_address', 'notes',
    ];

    protected $auditModule = 'suppliers';

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'expected_delivery_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(InventoryWarehouse::class, 'warehouse_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(PurchaseQuote::class, 'quote_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'order_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(PurchaseInvoice::class, 'order_id');
    }

    public function statusEvents(): MorphMany
    {
        return $this->morphMany(PurchaseStatusEvent::class, 'trackable');
    }

    public static function statusOptions(): array
    {
        return ['draft', 'sent', 'confirmed', 'partial_received', 'received', 'completed', 'cancelled'];
    }

    public function isConfirmed(): bool
    {
        return ! in_array($this->status, ['draft', 'cancelled']);
    }
}
