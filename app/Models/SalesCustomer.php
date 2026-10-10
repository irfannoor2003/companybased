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
        'company_name', 'short_code', 'contact_name', 'email', 'phone', 'mobile',
        'address', 'city', 'country', 'tax_number', 'price_list_id',
        'credit_limit', 'opening_balance', 'opening_balance_date',
        'currency', 'is_active', 'notes',
    ];

    protected $auditModule = 'sales';

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'opening_balance' => 'decimal:2',
            'opening_balance_date' => 'date',
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
                ->orWhere('short_code', 'like', "%{$term}%")
                ->orWhere('contact_name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('tax_number', 'like', "%{$term}%");
        });
    }

    /**
     * The balance the customer was already carrying before their first document
     * in this system was ever created.
     *
     * Stored signed rather than as an amount plus a direction flag: a single
     * value cannot be persisted as a positive number with a contradicting
     * "credit" flag, which is the failure mode that silently flips a sign.
     */
    public function openingBalance(): float
    {
        return round((float) $this->opening_balance, 2);
    }

/**
 * Outstanding balance = opening balance + issued invoice totals - payments -
 * applied credit notes.
 *
 * Positive means the customer owes us; negative means we owe them, which is
 * credit they can spend on the next invoice.
 *
 * Two things here are easy to get wrong and were both wrong at some point:
 *
 *  - The opening balance belongs in the figure, not alongside it. It is what the
 *    customer already owed (or was already credited with) when they were first
 *    added, so leaving it out made every balance and every statement wrong by
 *    that amount.
 *
 *  - `paid` invoices must be counted. They used to be excluded, on the reasoning
 *    that a settled bill is not outstanding — but the payments that settled them
 *    were still subtracted, so a customer who had paid everything in full
 *    appeared to hold credit equal to every invoice they had ever paid.
 *
 *  - Money is converted to base currency before anything is added together. Every
 *    document carries its own `exchange_rate`, so summing `total` across invoices
 *    added USD to PKR: a customer with a USD 100 invoice and a PKR 50,000 invoice
 *    reported 50,100, which is not a quantity of anything. Each figure is now
 *    multiplied by its own rate first, so the balance is always in base currency
 *    and two documents can be summed without nonsense.
 *
 * Note what is deliberately absent: an invoice's `credit_applied`. That column
 * says *how this document was settled*, for the invoice's own arithmetic and for
 * showing it on the document. The money behind it is already a payment row, so
 * subtracting it here as well would count the same money twice — a customer who
 * overpaid 2,000 and had 900 of it applied to their next bill would look 1,100 in
 * credit while actually holding 1,100... and 900 more than they should.
 */
    public function balance(): float
    {
        $invoices = $this->invoices()
            ->whereIn('status', ['sent', 'partially_paid', 'overdue', 'paid']);

        // Converted per row, in SQL, so two currencies can never be added raw.
        // `exchange_rate` is the rate that took the document's face value to base
        // currency when it was issued, and it is snapshotted onto the document
        // precisely so history does not move when the rate is later re-entered.
        //
        // COALESCE guards a document saved without a rate: treating NULL as 1
        // keeps it at face value, which is what the ledger does with the same
        // column, rather than silently dropping the invoice from the balance.
        $billed = (float) $invoices->selectRaw('COALESCE(SUM(total * COALESCE(exchange_rate, 1)), 0) AS total')->value('total');

        $paid = (float) $this->payments()
            ->selectRaw('COALESCE(SUM(amount * COALESCE(exchange_rate, 1)), 0) AS total')
            ->value('total');

        $credited = (float) $this->creditNotes()
            ->selectRaw('COALESCE(SUM(applied_amount * COALESCE(exchange_rate, 1)), 0) AS total')
            ->value('total');

        // The opening balance is already in base currency: it is entered by hand
        // against the company's own books, with no rate to apply.
        return round($this->openingBalance() + $billed - $paid - $credited, 2);
    }

    /**
     * Money this customer holds with us — an overpayment, a deposit, or an
     * opening credit. Never negative: a customer who owes money holds none.
     *
     * The next invoice draws on this automatically.
     */
    public function creditAvailable(): float
    {
        return round(max(0.0, -$this->balance()), 2);
    }

    /**
     * A convenience flag for the UI so the "in credit" wording lives with the
     * sign convention rather than being re-derived in every view.
     */
    public function hasCredit(): bool
    {
        return $this->creditAvailable() > 0.0;
    }
}
