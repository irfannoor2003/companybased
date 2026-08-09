<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;

class SalesCustomer extends Model
{
    use Auditable;
    use Notifiable;
    use SoftDeletes;

    protected $table = 'sales_customers';

    protected $fillable = [
        'company_name', 'contact_name', 'email', 'phone', 'mobile',
        'address', 'city', 'country', 'tax_number', 'price_list_id',
        'credit_limit', 'currency', 'is_active', 'notes',
    ];

    protected $auditModule = 'sales';

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(SalesQuote::class, 'customer_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'customer_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class, 'customer_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalesPayment::class, 'customer_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(SalesCreditNote::class, 'customer_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('company_name', 'like', "%{$term}%")
                ->orWhere('contact_name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('tax_number', 'like', "%{$term}%");
        });
    }

    /**
     * Outstanding balance = sum of invoice totals - payments - applied credit notes.
     */
    public function balance(): float
    {
        $billed = (float) $this->invoices()->whereIn('status', ['sent', 'partially_paid', 'overdue'])->sum('total');
        $paid = (float) $this->payments()->sum('amount');
        $credited = (float) $this->creditNotes()->sum('applied_amount');

        return round($billed - $paid - $credited, 2);
    }
}
