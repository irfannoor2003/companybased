<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Manually maintained reference rates used to convert a document's currency
 * into the company's base currency. Rates are snapshot onto each document at
 * creation time via the document's own `exchange_rate` column; this table only
 * feeds that snapshot and the admin-managed Settings -> Currencies screen.
 */
class ExchangeRate extends Model
{
    protected $table = 'exchange_rates';

    protected $fillable = ['currency_code', 'rate_to_base', 'effective_date'];

    protected function casts(): array
    {
        return [
            'rate_to_base' => 'decimal:6',
            'effective_date' => 'date',
        ];
    }

    public static function latestFor(string $currencyCode, ?string $effectiveDate = null): ?self
    {
        return static::query()
            ->where('currency_code', strtoupper($currencyCode))
            ->when($effectiveDate, fn ($q) => $q->where('effective_date', '<=', $effectiveDate))
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();
    }
}
