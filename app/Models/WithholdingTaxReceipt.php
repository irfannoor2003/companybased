<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WithholdingTaxReceipt extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $table = 'withholding_tax_receipts';

    protected $fillable = [
        'number', 'customer_id', 'invoice_id', 'receipt_date', 'amount',
        'tax_rate_percent', 'tax_amount', 'currency', 'notes',
    ];

    protected $auditModule = 'sales';

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date',
            'amount' => 'decimal:2',
            'tax_rate_percent' => 'decimal:2',
            'tax_amount' => 'decimal:2',
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
}
