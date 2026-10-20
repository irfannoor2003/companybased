<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Services\Accounting\ProductionAbsorptionService;
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

        /**
         * Manufacturing costing.
         *
         * Seeded so they are discoverable rather than only existing as a fallback in
         * code, but the DEFAULTS are the conservative ones on purpose.
         *
         * `overhead_rate_per_hour` is zero, which means overhead is not absorbed at all
         * until somebody configures it. Absorbing a rate nobody chose would invent
         * factory cost and capitalise it into inventory.
         *
         * `absorption_basis` is `labour_hour` because that is what every existing
         * installation has been doing. Work centres now record the minutes a job took,
         * so `work_centre_hour` is measurable and available -- but a shop must switch to
         * it deliberately, because the two bases value the same job differently and
         * silently repricing live inventory is not a change to discover in a margin
         * report.
         */
        Setting::setMany([
            'production.standard_monthly_hours' => '208',
            'production.overhead_rate_per_hour' => '0',
            'production.absorption_basis' => ProductionAbsorptionService::DEFAULT_BASIS,
        ], 'production');

        Setting::flushCache();
    }
}
