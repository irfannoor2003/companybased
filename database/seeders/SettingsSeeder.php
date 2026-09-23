<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        Setting::setMany([
            'company.name' => 'Nexos Digital',
            'company.tagline' => 'Business management, simplified.',
            'company.email' => 'hello@nexosdigital.test',
            'company.phone' => null,
            'company.website' => null,
            'company.address' => null,
            'company.registration_number' => null,
            'company.tax_number' => null,
            'company.currency' => 'PKR',
            'base_currency' => 'PKR',
            'company.fiscal_year_start' => '2026-01-01',
            'company.timezone' => 'UTC',
            'company.date_format' => 'M d, Y',
            'company.latitude' => '5.6037',
            'company.longitude' => '-0.1870',
            'company.radius' => '500',
            'company.qr_code_text' => 'NEXOSDIGITAL-OFFICE-ATTENDANCE-2026',
            'branding.primary_color' => '#4f46e5',
            'branding.accent_color' => '#0ea5e9',
            'branding.logo' => 'branding/logo.png',
            'branding.favicon' => 'branding/favicon.ico',
            'branding.dark_mode' => 'system',
            'notifications.email_enabled' => '1',
            'notifications.email_from' => 'no-reply@nexosdigital.test',
        ], 'general');

        Setting::flushCache();
    }
}
