<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryIncomingShipment extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'supplier_id', 'warehouse_id', 'purchase_order_id', 'number',
        'expected_arrival_at', 'status', 'arrived_at', 'approved_at', 'notes',
    ];

    protected $auditModule = 'inventory';

    protected function casts(): array
    {
        return [
            'expected_arrival_at' => 'date',
            'arrived_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(InventoryWarehouse::class, 'warehouse_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryIncomingShipmentItem::class, 'shipment_id');
    }

    public static function statusOptions(): array
    {
        return ['pending', 'in_transit', 'arrived', 'approved'];
    }

    /**
     * States before approval — still awaiting physical receipt and sign-off.
     */
    public function scopePendingIncoming(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'in_transit', 'arrived']);
    }

    public function isLocked(): bool
    {
        return $this->status === 'approved';
    }
}
