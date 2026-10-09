<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesCreditNote extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $table = 'sales_credit_notes';

    protected $fillable = [
        'number', 'number', 'reference_number', 'invoice_id', 'customer_id', 'issue_date', 'reason',
        'currency', 'exchange_rate', 'subtotal', 'tax_amount', 'total', 'applied_amount', 'credit_note_applied', 'notes',
    ];

    protected $auditModule = 'sales';

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'exchange_rate' => 'decimal:6',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'applied_amount' => 'decimal:2',
            'credit_note_applied' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(SalesCustomer::class, 'customer_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'invoice_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesCreditNoteItem::class, 'credit_note_id');
    }

    /**
     * Value this note has not yet used to settle an invoice.
     *
     * The excess of a large note: `applied_amount` credits the customer account
     * with the whole note, while `credit_note_applied` records only what the
     * linked invoice could absorb. The difference is credit the customer holds
     * that has not reduced any bill yet, which is why the next invoice can be
     * settled by it.
     */
    public function remaining(): float
    {
        return round((float) $this->total - (float) $this->credit_note_applied, 2);
    }
}
