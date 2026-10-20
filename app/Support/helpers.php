<?php

use App\Models\ExchangeRate;
use App\Models\Module;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (! function_exists('settings')) {
    /**
     * Retrieve a company setting with a default fallback.
     */
    function settings(string $key, mixed $default = null): mixed
    {
        return Setting::get($key, $default);
    }
}

if (! function_exists('module_enabled')) {
    /**
     * Whether a module is enabled for this company deployment.
     */
    function module_enabled(string $key): bool
    {
        return Module::isEnabled($key);
    }
}

if (! function_exists('company_name')) {
    function company_name(): string
    {
        return (string) (settings('company.name') ?: config('app.name', 'Nexos Digital'));
    }
}

if (! function_exists('company_logo_path')) {
    /**
     * The absolute path to the company logo, or null when there isn't one to print.
     *
     * `branding.logo` is a stored path that says which file to use, not a promise
     * that the file is there. SettingsSeeder ships `branding/logo.png` as the
     * default, and that file is not in the repository -- so on a fresh deployment
     * every printed document carried an empty grey box where the logo belonged,
     * in the rail and in the masthead alike.
     *
     * The same thing happens to any deployment whose administrator deletes the
     * uploaded file but not the setting pointing at it.
     *
     * Returns null in both cases, and every template tests for null. Printing a
     * broken-image placeholder is worse than printing no logo: the customer reads
     * the box as a fault in the document rather than as a company with no logo.
     */
    function company_logo_path(): ?string
    {
        $logo = settings('branding.logo');

        if (blank($logo)) {
            return null;
        }

        $path = storage_path('app/public/'.ltrim((string) $logo, '/'));

        return is_file($path) ? $path : null;
    }
}

if (! function_exists('pdf_options')) {
    /**
     * The DomPDF options every render in this application should use.
     *
     * WHY THIS EXISTS
     *
     * `Barryvdh\DomPDF\Pdf::setOptions()` rebuilds DomPDF's whole options object.
     * Call it and the `chroot` stops applying, DomPDF stops being allowed to read
     * local files, and every `<img>` pointing at a real path on the server silently
     * becomes a broken-image placeholder. No exception, no warning, no failed
     * request -- the document just prints without its logo.
     *
     * It was found by rendering a document to PNG and looking at it, which is the
     * only check that can see this class of fault: the PDF is valid, the text is all
     * there, every assertion passes.
     *
     * `chroot` is therefore named explicitly here rather than left to the config
     * file, so a caller cannot forget it. It is the setting that decides whether
     * DomPDF may read from disk at all, which makes it the one thing a render call
     * should never leave to a merge.
     *
     * PHP and remote access stay off. Every document is built from our own Blade
     * templates, so neither has anything to fetch.
     *
     * @return array<string, mixed>
     */
    function pdf_options(): array
    {
        return [
            'chroot' => [
                public_path(),
                storage_path('app/public'),
                base_path(),
            ],
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
        ];
    }
}

if (! function_exists('money')) {
    /**
     * Format a money value for display using the configured company currency.
     * Storage never uses floats — this only renders a decimal string.
     */
    function money(int|string|float|null $amount, ?string $currency = null): string
    {
        $currency = $currency ?: (string) (settings('base_currency') ?: settings('company.currency') ?: 'USD');
        $amount = (float) ((string) ($amount ?? 0));

        if (class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter(app()->getLocale() ?: 'en', NumberFormatter::CURRENCY);
            $formatter->setSymbol(NumberFormatter::CURRENCY_SYMBOL, $currency);

            return (string) $formatter->format($amount);
        }

        return number_format($amount, 2).' '.$currency;
    }
}

if (! function_exists('base_currency')) {
    /**
     * The company-wide reporting currency used for rollups and aggregation.
     */
    function base_currency(): string
    {
        return strtoupper((string) (settings('base_currency') ?: settings('company.currency') ?: 'USD'));
    }
}

if (! function_exists('exchange_rate_for')) {
    /**
     * Latest reference rate converting the given currency to the company's base
     * currency. Returns 1.0 when the currency equals the base currency or no
     * rate has been configured. Used only when snapshotting a rate onto a new
     * document — never for recalculating an existing document.
     */
    function exchange_rate_for(?string $currency, ?string $effectiveDate = null): float
    {
        $currency = strtoupper((string) ($currency ?: base_currency()));

        if ($currency === base_currency()) {
            return 1.0;
        }

        $rate = ExchangeRate::latestFor($currency, $effectiveDate)?->rate_to_base;

        return $rate !== null ? (float) $rate : 1.0;
    }
}

if (! function_exists('to_base_currency')) {
    /**
     * Convert an amount from a document's currency to the company's base
     * currency using the rate snapshot stored on that document. Pure arithmetic
     * for aggregation — never used to re-display a single document in its own
     * currency (money() already handles that).
     */
    function to_base_currency(int|string|float|null $amount, int|string|float $exchangeRate = 1.0): float
    {
        return round((float) ((string) ($amount ?? 0)) * (float) $exchangeRate, 2);
    }
}

if (! function_exists('currency_options')) {
    /**
     * Returns an array of common ISO 4217 currency codes keyed by code.
     * Used for <select> dropdowns across the app.
     */
    function currency_options(): array
    {
        return [
            'USD' => 'USD — US Dollar',
            'EUR' => 'EUR — Euro',
            'GBP' => 'GBP — British Pound',
            'JPY' => 'JPY — Japanese Yen',
            'CAD' => 'CAD — Canadian Dollar',
            'AUD' => 'AUD — Australian Dollar',
            'CHF' => 'CHF — Swiss Franc',
            'CNY' => 'CNY — Chinese Yuan',
            'INR' => 'INR — Indian Rupee',
            'PKR' => 'PKR — Pakistani Rupee',
            'AED' => 'AED — UAE Dirham',
            'SAR' => 'SAR — Saudi Riyal',
            'MYR' => 'MYR — Malaysian Ringgit',
            'SGD' => 'SGD — Singapore Dollar',
            'HKD' => 'HKD — Hong Kong Dollar',
            'BRL' => 'BRL — Brazilian Real',
            'MXN' => 'MXN — Mexican Peso',
            'KWD' => 'KWD — Kuwaiti Dinar',
            'QAR' => 'QAR — Qatari Riyal',
        ];
    }
}

if (! function_exists('next_document_number')) {
    /**
     * Generate the next sequential document number for a given document type,
     * e.g. next_document_number('invoice', 'INV') => "INV-2026-0001".
     * The counter is stored in settings so numbers never repeat.
     * Pass a model class to skip numbers already taken in its table.
     */
    function next_document_number(string $type, string $prefix, ?string $modelClass = null): string
    {
        $key = 'counters.'.$type;

        return DB::transaction(function () use ($key, $prefix, $modelClass): string {
            $setting = Setting::query()->where('key', $key)->lockForUpdate()->first();

            if (! $setting) {
                Setting::query()->insertOrIgnore([
                    'key' => $key,
                    'value' => '0',
                    'group' => 'counters',
                    'is_public' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $setting = Setting::query()->where('key', $key)->lockForUpdate()->firstOrFail();
            }

            $next = ((int) $setting->value) + 1;

            Setting::flushCache();

            do {
                $number = $prefix.'-'.now()->format('Y').'-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);

                // Persist the value actually issued. This used to increment $next
                // *before* storing it, so the counter always landed one past the
                // number handed out and every sequential document skipped the
                // odd one: INV-2026-0001, 0003, 0005. The gaps are
                // indistinguishable from deleted records in an audit trail, and
                // the counter drifted twice as fast as the document count.
                $setting->update(['value' => (string) $next]);

                $next++;
            } while ($modelClass !== null && $modelClass::withTrashed()->where('number', $number)->exists());

            Setting::flushCache();

            return $number;
        });
    }
}

if (! function_exists('unique_slug')) {
    /**
     * Generate a URL-safe slug unique against a model's column, appending a
     * numeric suffix on collisions (optionally ignoring a given row id).
     *
     * @param  class-string<Model>  $model
     */
    function unique_slug(string $model, string $value, string $column = 'slug', ?int $ignoreId = null): string
    {
        $base = Str::slug($value) ?: 'item';
        $slug = $base;
        $suffix = 1;

        $query = $model::withTrashed()->where($column, $slug);

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        while ($query->exists()) {
            $slug = $base.'-'.(++$suffix);
            $query = $model::withTrashed()->where($column, $slug);
            if ($ignoreId !== null) {
                $query->whereKeyNot($ignoreId);
            }
        }

        return $slug;
    }
}

if (! function_exists('hex_to_rgb')) {
    /**
     * Convert #RRGGBB to an "r g b" triplet string usable with the rgb() Tailwind
     * color tokens, e.g. "#4f46e5" => "79 70 229".
     */
    function hex_to_rgb(?string $hex, string $fallback = '79 70 229'): string
    {
        $hex = ltrim((string) $hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return $fallback;
        }

        return implode(' ', [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ]);
    }
}

if (! function_exists('darken_hex')) {
    /**
     * Darken a hex colour by a percentage (0-100). Returns normalized #rrggbb.
     */
    function darken_hex(?string $hex, float $percent = 15): string
    {
        $hex = ltrim((string) $hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = '4f46e5';
        }

        $factor = 1 - max(0, min(1, $percent / 100));

        $out = '#';
        foreach ([0, 2, 4] as $i) {
            $channel = hexdec(substr($hex, $i, 2)) * $factor;
            $out .= str_pad(dechex((int) round($channel)), 2, '0', STR_PAD_LEFT);
        }

        return $out;
    }
}

if (! function_exists('mix_hex')) {
    /**
     * Blend two hex colours, $percent being how much of $to ends up in the result.
     *
     * Needed where a colour has to be derived from a tenant's own brand choice —
     * the muted text on a dark rail, for instance. A hard-coded grey there looks
     * correct against one brand colour and wrong against every other, and CSS
     * rgba() cannot be used because the backdrop is itself dynamic.
     */
    function mix_hex(?string $from, string $to, float $percent = 50): string
    {
        $normalise = static function (?string $hex, string $fallback): string {
            $hex = ltrim((string) $hex, '#');

            if (strlen($hex) === 3) {
                $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
            }

            return preg_match('/^[0-9a-fA-F]{6}$/', $hex) ? $hex : $fallback;
        };

        $from = $normalise($from, '000000');
        $to = $normalise($to, 'ffffff');

        $weight = max(0, min(1, $percent / 100));

        $out = '#';
        foreach ([0, 2, 4] as $i) {
            $a = hexdec(substr($from, $i, 2));
            $b = hexdec(substr($to, $i, 2));
            $out .= str_pad(dechex((int) round($a + ($b - $a) * $weight)), 2, '0', STR_PAD_LEFT);
        }

        return $out;
    }
}
