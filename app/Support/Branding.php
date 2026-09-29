<?php

namespace App\Support;

/**
 * Single source of truth for the per-request brand payload shared with every
 * view as `$appBrand`.
 *
 * Previously two independent view composers (AppServiceProvider and the
 * ShareAppSettings middleware) both registered `$appBrand` with divergent
 * payloads — different keys and a different darken percentage — so which one
 * won depended on registration order. Both now resolve through here.
 */
final class Branding
{
    private const DEFAULT_PRIMARY = '#4f46e5';

    private const DEFAULT_ACCENT = '#0ea5e9';

    /**
     * Percentage the "strong" (hover) brand colour is darkened by.
     */
    private const STRONG_DARKEN_PERCENT = 12;

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $cached = null;

    /**
     * The brand payload for this request.
     *
     * @return array{companyName: string, favicon: ?string, logo: ?string, primaryColor: string, primaryRgb: string, primaryStrongRgb: string, accentRgb: string, darkMode: string}
     */
    public static function payload(): array
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $primary = (string) (settings('branding.primary_color') ?: self::DEFAULT_PRIMARY);
        $accent = (string) (settings('branding.accent_color') ?: self::DEFAULT_ACCENT);

        return self::$cached = [
            'companyName' => (string) (settings('company.name') ?: config('app.name', 'Company ERP')),
            'favicon' => settings('branding.favicon'),
            'logo' => settings('branding.logo'),
            'primaryColor' => $primary,
            'primaryRgb' => hex_to_rgb($primary, '79 70 229'),
            'primaryStrongRgb' => hex_to_rgb(darken_hex($primary, self::STRONG_DARKEN_PERCENT), '67 56 202'),
            'accentRgb' => hex_to_rgb($accent, '14 165 233'),
            'darkMode' => (string) settings('branding.dark_mode', 'system'),
        ];
    }

    /**
     * Drop the memoized payload. Required whenever settings are written during
     * the request (e.g. CompanyController::updateBranding()).
     */
    public static function flush(): void
    {
        self::$cached = null;
    }
}
