<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        Setting::setMany([
            'company.name' => 'CompanyBase',
            'company.tagline' => 'Business management, simplified.',
            'company.email' => 'hello@companybased.test',
            'company.phone' => null,
            'company.website' => null,
            'company.address' => null,
            'company.registration_number' => null,
            'company.tax_number' => null,
            'company.currency' => 'USD',
            'base_currency' => 'USD',
            'company.fiscal_year_start' => '2026-01-01',
            'company.timezone' => 'UTC',
            'company.date_format' => 'M d, Y',
            'company.latitude' => '5.6037',
            'company.longitude' => '-0.1870',
            'company.radius' => '500',
            'company.qr_code_text' => 'COMPANYBASE-OFFICE-ATTENDANCE-2026',
            'branding.primary_color' => '#4f46e5',
            'branding.accent_color' => '#0ea5e9',
            'branding.logo' => null,
            'branding.favicon' => null,
            'branding.dark_mode' => 'system',
            'notifications.email_enabled' => '1',
            'notifications.email_from' => 'no-reply@companybased.test',
        ], 'general');

        Setting::flushCache();
    }
}
