<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PosSale extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $auditModule = 'pos';

    protected $fillable = [
        'receipt_number', 'shift_id', 'customer_name', 'subtotal', 'discount',
        'tax', 'total', 'payment_method_id', 'amount_paid', 'change_due',
        'status', 'sold_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'sold_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'change_due' => 'decimal:2',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'receipt_number';
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(PosShift::class, 'shift_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PosPaymentMethod::class, 'payment_method_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PosSaleItem::class, 'pos_sale_id');
    }

    public static function statusOptions(): array
    {
        return ['completed', 'void'];
    }
}