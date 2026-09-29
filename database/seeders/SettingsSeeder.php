<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Company identity only.
 *
 * A fresh deployment is seeded with nothing but the company name, its branding,
 * and the handful of values the application cannot boot or render without.
 * Everything else — attendance rules, office GPS coordinates, mail identity,
 * notification channels — is left unset on purpose so each deployment
 * configures its own under Settings rather than inheriting a developer's values.
 *
 * Every key read elsewhere in the application has a fallback:
 *  - company.timezone    -> ShareAppSettings defaults to UTC
 *  - base_currency       -> money() and base_currency() default to USD
 *  - company.date_format -> views fall back when unset
 *  - mail.*              -> MailIdentity derives addresses from APP_URL
 *
 * Chart of accounts is not seeded either: it is company-specific bookkeeping
 * that an accountant should set up, and no module auto-generates journal
 * entries, so the financial statements stay empty until they do.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        Setting::setMany([
            // ── Identity ──────────────────────────────────────────────
            'company.name' => 'Nexos Digital',
            'company.currency' => 'USD',
            'base_currency' => 'USD',

            // ── Branding ──────────────────────────────────────────────
            'branding.logo' => 'branding/logo.png',
            'branding.favicon' => 'branding/favicon.ico',
            'branding.primary_color' => '#4f46e5',
            'branding.accent_color' => '#0ea5e9',
            'branding.dark_mode' => 'system',
        ], 'general');

        Setting::flushCache();
    }
}
