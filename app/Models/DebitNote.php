<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DebitNote extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'number', 'invoice_id', 'supplier_id', 'issue_date', 'reason',
        'currency', 'subtotal', 'tax_amount', 'total', 'applied_amount', 'notes',
    ];

    protected $auditModule = 'suppliers';

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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'invoice_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DebitNoteItem::class, 'debit_note_id');
    }

    public function remaining(): float
    {
        return round((float) $this->total - (float) $this->applied_amount, 2);
    }
}
