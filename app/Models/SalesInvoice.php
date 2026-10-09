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
        'number', 'number', 'reference_number', 'order_id', 'customer_id', 'issue_date', 'due_date',
        'status', 'template', 'currency', 'exchange_rate', 'subtotal', 'discount_amount', 'tax_amount', 'total',
        'paid_amount', 'credit_applied', 'credit_note_applied', 'withheld_amount', 'notes',
    ];

    protected $auditModule = 'sales';

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'exchange_rate' => 'decimal:6',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'credit_applied' => 'decimal:2',
            'credit_note_applied' => 'decimal:2',
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

    public static function templateOptions(): array
    {
        return ['classic' => 'Classic', 'modern' => 'Modern', 'minimal' => 'Minimal', 'corporate' => 'Corporate'];
    }

    /**
     * What is still owed on this invoice.
     *
     * Three separate mechanisms can settle an invoice, and each has to be
     * deducted or the same money is collectable twice:
     *
     *  - paid_amount — cash receipts, always the sum of the payment rows.
     *  - credit_applied — the customer's own forward balance, money they handed
     *    over on an earlier document.
     *  - credit_note_applied — value returned by a credit note raised against
     *    this invoice.
     */
    public function balance(): float
    {
        return round(
            (float) $this->total
            - (float) $this->paid_amount
            - (float) $this->credit_applied
            - (float) $this->credit_note_applied,
            2,
        );
    }

    /**
     * Total settled on the invoice, in cash, forward credit and credit notes.
     */
    public function settledAmount(): float
    {
        return round(
            (float) $this->paid_amount
            + (float) $this->credit_applied
            + (float) $this->credit_note_applied,
            2,
        );
    }

    public function isPaid(): bool
    {
        return $this->balance() <= 0;
    }
}
