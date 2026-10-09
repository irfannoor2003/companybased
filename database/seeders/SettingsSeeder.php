<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * The company profile this deployment ships with.
 *
 * These are the values the Super Admin would otherwise have to type in by hand
 * on a fresh install, so they are seeded once here. Everything still remains
 * editable under Settings → Company Profile — seeding only sets the starting
 * point, it does not lock anything.
 *
 * Every key read elsewhere in the application also has a fallback, so a partially
 * configured install degrades rather than breaks:
 *  - company.timezone    -> ShareAppSettings defaults to UTC
 *  - base_currency       -> money() and base_currency() default to USD
 *  - company.date_format -> views fall back when unset
 *  - mail.*              -> MailIdentity derives addresses from APP_URL
 *
 * Deliberately still not seeded:
 *  - the chart of accounts, which is company-specific bookkeeping an accountant
 *    should set up, and which no module auto-generates entries for
 *  - mail identity and SMTP credentials, which the Super Admin configures under
 *    Settings → Mail Server
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        Setting::setMany([
            // ── Identity ──────────────────────────────────────────────
            'company.name' => 'Nexos Digital',
            'company.tagline' => 'Digital solutions that move your business forward.',
            'company.email' => 'portalinfo@nexosdigital.com',
            'company.phone' => '03214245840',
            'company.website' => 'https://nexosdigitalagency.com/',
            'company.address' => 'Quaid-e-Azam Interchange, Lahore, Pakistan',

            // ── Invoice rail ───────────────────────────────────────────
            // Printed down the left edge of every invoice and document. Seeded
            // empty on purpose: a document that shows a "Payment" heading over
            // blank values is worse than one with no payment block at all, so
            // the template renders nothing until an admin fills these in.
            'company.bank_account_name' => '',
            'company.bank_name' => '',
            'company.bank_account_number' => '',
            'company.payment_instructions' => '',
            'company.payment_terms' => '',

            // ── Money and locale ──────────────────────────────────────
            'company.currency' => 'PKR',
            'base_currency' => 'PKR',
            'company.timezone' => 'Asia/Karachi',
            'company.date_format' => 'd M Y',

            // ── Office location (GPS attendance check-in radius) ──────
            'company.latitude' => '31.589756005827',
            'company.longitude' => '74.43116302496162',
            'company.radius' => '500',

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
