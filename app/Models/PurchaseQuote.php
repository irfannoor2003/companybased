<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseQuote extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'number', 'supplier_id', 'issue_date', 'valid_until',
        'status', 'currency', 'subtotal', 'discount_amount', 'tax_amount', 'total',
        'notes', 'converted_to_order_id',
    ];

    protected $auditModule = 'suppliers';

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'valid_until' => 'date',
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

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseQuoteItem::class, 'quote_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'converted_to_order_id');
    }

    public function statusEvents(): MorphMany
    {
        return $this->morphMany(PurchaseStatusEvent::class, 'trackable');
    }

    public static function statusOptions(): array
    {
        return ['draft', 'sent', 'accepted', 'rejected', 'converted'];
    }

    public function isConverted(): bool
    {
        return $this->status === 'converted';
    }
}
