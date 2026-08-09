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
        'number', 'invoice_id', 'customer_id', 'issue_date', 'reason',
        'currency', 'subtotal', 'tax_amount', 'total', 'applied_amount', 'notes',
    ];

    protected $auditModule = 'sales';

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'applied_amount' => 'decimal:2',
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

    public function remaining(): float
    {
        return round((float) $this->total - (float) $this->applied_amount, 2);
    }
}
