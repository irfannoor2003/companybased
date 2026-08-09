<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesRecurringInvoiceItem extends Model
{
    protected $table = 'sales_recurring_invoice_items';

    protected $fillable = [
        'recurring_invoice_id', 'product_id', 'description', 'qty', 'unit_price',
        'discount_percent', 'tax_percent', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'tax_percent' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function recurringInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesRecurringInvoice::class, 'recurring_invoice_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
