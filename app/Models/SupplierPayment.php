<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierPayment extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'number', 'invoice_id', 'supplier_id', 'amount', 'payment_date',
        'method', 'reference', 'currency', 'exchange_rate', 'notes',
    ];

    protected $auditModule = 'suppliers';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
            'exchange_rate' => 'decimal:6',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'invoice_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public static function methodOptions(): array
    {
        return ['cash', 'bank_transfer', 'card', 'cheque', 'other'];
    }
}
