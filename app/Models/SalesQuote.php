<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesQuote extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $table = 'sales_quotes';

    protected $fillable = [
        'number', 'customer_id', 'price_list_id', 'issue_date', 'valid_until',
        'status', 'currency', 'subtotal', 'discount_amount', 'tax_amount', 'total',
        'notes', 'converted_to_order_id',
    ];

    protected $auditModule = 'sales';

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'valid_until' => 'date',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(SalesCustomer::class, 'customer_id');
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesQuoteItem::class, 'quote_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'converted_to_order_id');
    }

    public static function statusOptions(): array
    {
        return ['draft', 'sent', 'accepted', 'rejected', 'converted'];
    }

    public function isConverted(): bool
    {
        return $this->status === 'converted';
    }
}
