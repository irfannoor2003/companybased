<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesInvoice extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $table = 'sales_invoices';

    protected $fillable = [
        'number', 'order_id', 'customer_id', 'issue_date', 'due_date',
        'status', 'currency', 'subtotal', 'discount_amount', 'tax_amount', 'total',
        'paid_amount', 'withheld_amount', 'notes',
    ];

    protected $auditModule = 'sales';

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
            'withheld_amount' => 'decimal:2',
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
        return $this->hasMany(SalesInvoiceItem::class, 'invoice_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalesPayment::class, 'invoice_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(SalesCreditNote::class, 'invoice_id');
    }

    public function statusEvents(): MorphMany
    {
        return $this->morphMany(SalesStatusEvent::class, 'trackable');
    }

    public function withholdingTaxReceipts(): HasMany
    {
        return $this->hasMany(WithholdingTaxReceipt::class, 'invoice_id');
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
