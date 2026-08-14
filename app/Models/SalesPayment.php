<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesPayment extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $table = 'sales_payments';

    protected $fillable = [
        'number', 'invoice_id', 'customer_id', 'amount', 'payment_date', 'method',
        'reference', 'currency', 'exchange_rate', 'notes',
    ];

    protected $auditModule = 'sales';

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
        return $this->belongsTo(SalesInvoice::class, 'invoice_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(SalesCustomer::class, 'customer_id');
    }

    public static function methodOptions(): array
    {
        return ['cash', 'bank_transfer', 'card', 'cheque', 'other'];
    }
}
