<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseInvoice extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'number', 'order_id', 'supplier_id', 'issue_date', 'due_date',
        'status', 'currency', 'subtotal', 'discount_amount', 'tax_amount', 'total',
        'paid_amount', 'notes',
    ];

    protected $auditModule = 'suppliers';

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceItem::class, 'invoice_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class, 'invoice_id');
    }

    public function debitNotes(): HasMany
    {
        return $this->hasMany(DebitNote::class, 'invoice_id');
    }

    public function statusEvents(): MorphMany
    {
        return $this->morphMany(PurchaseStatusEvent::class, 'trackable');
    }

    public static function statusOptions(): array
    {
        return ['draft', 'sent', 'partially_paid', 'paid', 'overdue', 'cancelled'];
    }

    public function balance(): float
    {
        return round((float) $this->total - (float) $this->paid_amount, 2);
    }

    public function isPaid(): bool
    {
        return $this->balance() <= 0;
    }
}
