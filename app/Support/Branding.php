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
     * How far the brand colour is mixed toward white for use as TEXT in dark mode.
     *
     * A tenant's brand colour is chosen to look right as a button fill with white
     * text on it, which means it is a mid-to-dark tone. Dropped onto the dark
     * surface unchanged it is unreadable -- the default #4f46e5 measured 2.92:1
     * there. Lightening it for dark-mode text keeps it recognisably the same brand
     * while reaching 9.21:1.
     *
     * 55% white, not a fixed palette entry, so a custom brand colour gets the same
     * correction instead of silently reverting to the default indigo.
     */
    private const DARK_INK_LIGHTEN_PERCENT = 55;

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $cached = null;

    /**
     * The brand payload for this request.
     *
     * @return array{companyName: string, favicon: ?string, logo: ?string, primaryColor: string, primaryRgb: string, primaryStrongRgb: string, primaryInkRgb: string, accentRgb: string, darkMode: string}
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
            'primaryInkRgb' => hex_to_rgb(
                mix_hex($primary, '#ffffff', self::DARK_INK_LIGHTEN_PERCENT),
                '165 180 252',
            ),
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
