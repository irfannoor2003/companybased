<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class CurrencyController extends Controller
{
    public function index(): View
    {
        $baseCurrency = base_currency();

        $currencies = $this->currenciesInUse()
            ->reject(fn (string $code) => $code === $baseCurrency)
            ->values()
            ->map(fn (string $code) => [
                'code' => $code,
                'rate' => ExchangeRate::latestFor($code)?->rate_to_base,
                'effective_date' => ExchangeRate::latestFor($code)?->effective_date?->format('Y-m-d') ?? now()->toDateString(),
            ]);

        return view('settings.currencies', compact('baseCurrency', 'currencies'));
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizePermission('settings.currencies.manage');

        $data = $request->validate([
            'rates' => ['nullable', 'array'],
            'rates.*.currency_code' => ['nullable', 'string', 'max:8'],
            'rates.*.rate_to_base' => ['nullable', 'numeric', 'gt:0'],
            'rates.*.effective_date' => ['nullable', 'date'],
        ]);

        $baseCurrency = base_currency();

        foreach (($data['rates'] ?? []) as $rate) {
            $code = strtoupper(trim((string) ($rate['currency_code'] ?? '')));

            if ($code === '' || $code === $baseCurrency || ! isset($rate['rate_to_base'])) {
                continue;
            }

            $effectiveDate = $rate['effective_date'] ?? now()->toDateString();

            ExchangeRate::updateOrCreate(
                ['currency_code' => $code, 'effective_date' => $effectiveDate],
                ['rate_to_base' => $rate['rate_to_base']],
            );
        }

        return back()->with('toasts', [['type' => 'success', 'message' => 'Exchange rates updated.']]);
    }

    /**
     * Distinct currency codes actually in use across documents and parties.
     */
    private function currenciesInUse(): Collection
    {
        $tables = [
            'sales_invoices', 'sales_quotes', 'sales_orders', 'sales_payments',
            'purchase_invoices', 'purchase_quotes', 'purchase_orders', 'supplier_payments',
            'withholding_tax_receipts', 'sales_credit_notes', 'sales_customers', 'suppliers',
            'price_lists', 'bank_accounts',
        ];

        $codes = collect();

        foreach ($tables as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $codes = $codes->merge(
                DB::table($table)
                    ->whereNotNull('currency')
                    ->where('currency', '!=', '')
                    ->distinct()
                    ->pluck('currency'),
            );
        }

        return $codes
            ->map(fn ($code) => strtoupper((string) $code))
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }
}
