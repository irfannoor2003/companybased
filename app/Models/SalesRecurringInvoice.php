<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesRecurringInvoice extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $table = 'sales_recurring_invoices';

    protected $fillable = [
        'customer_id', 'name', 'frequency', 'next_run_date', 'last_run_date',
        'day_of_cycle', 'currency', 'subtotal', 'tax_amount', 'total',
        'is_active', 'notes',
    ];

    protected $auditModule = 'sales';

    protected function casts(): array
    {
        return [
            'next_run_date' => 'date',
            'last_run_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(SalesCustomer::class, 'customer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesRecurringInvoiceItem::class, 'recurring_invoice_id');
    }

    public static function frequencyOptions(): array
    {
        return ['weekly', 'monthly', 'quarterly', 'yearly'];
    }
}
