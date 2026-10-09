<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'company_name', 'short_code', 'contact_name', 'email', 'phone', 'mobile',
        'address', 'city', 'country', 'tax_number', 'payment_terms',
        'currency', 'is_active', 'notes',
    ];

    protected $auditModule = 'suppliers';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function purchaseQuotes(): HasMany
    {
        return $this->hasMany(PurchaseQuote::class, 'supplier_id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'supplier_id');
    }

    public function purchaseInvoices(): HasMany
    {
        return $this->hasMany(PurchaseInvoice::class, 'supplier_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class, 'supplier_id');
    }

    public function debitNotes(): HasMany
    {
        return $this->hasMany(DebitNote::class, 'supplier_id');
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
                ->orWhere('short_code', 'like', "%{$term}%")
                ->orWhere('contact_name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('tax_number', 'like', "%{$term}%");
        });
    }

    /**
     * Outstanding payable = purchase invoice totals - payments - applied debit
     * notes.
     *
     * `paid` invoices are counted on purpose. They used to be excluded, on the
     * reasoning that a settled bill is no longer owed — but the payments that
     * settled them were still subtracted, so a supplier who had been paid in
     * full showed a negative balance equal to every invoice ever paid. The
     * company believed it owed money to each supplier it had already paid.
     */
    public function balance(): float
    {
        $billed = (float) $this->purchaseInvoices()
            ->whereIn('status', ['sent', 'partially_paid', 'overdue', 'paid'])
            ->sum('total');

        $paid = (float) $this->payments()->sum('amount');
        $credited = (float) $this->debitNotes()->sum('applied_amount');

        return round($billed - $paid - $credited, 2);
    }
}
