<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Visit;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CompanySettingsAndVisitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Subscription::create([
            'plan_name' => 'Test', 'starts_at' => now()->subMonth(),
            'expires_at' => now()->addYear(), 'is_active' => true,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_office_location_form_saves_without_profile_fields(): void
    {
        $superAdmin = User::where('email', 'superadmin@nexosdigital.test')->firstOrFail();

        // The Office Location card posts ONLY lat/lng/radius/qr_code_text.
        $response = $this->actingAs($superAdmin)->put('/settings/company', [
            'latitude' => '31.5204',
            'longitude' => '74.3587',
            'radius' => '750',
            'qr_code_text' => 'HQ-SCAN-2026',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        Setting::flushCache();

        $this->assertSame('31.5204', (string) settings('company.latitude'));
        $this->assertSame('74.3587', (string) settings('company.longitude'));
        $this->assertSame('750', (string) settings('company.radius'));
        $this->assertSame('HQ-SCAN-2026', (string) settings('company.qr_code_text'));

        // The profile fields must be untouched, not wiped.
        $this->assertNotEmpty(settings('company.name'), 'company name preserved');
        $this->assertNotEmpty(settings('company.currency'), 'currency preserved');
        $this->assertNotEmpty(settings('company.timezone'), 'timezone preserved');
        $this->assertNotEmpty(settings('company.date_format'), 'date_format preserved');
    }

    public function test_profile_form_still_saves_and_does_not_clobber_location(): void
    {
        $superAdmin = User::where('email', 'superadmin@nexosdigital.test')->firstOrFail();

        Setting::setMany(['company.latitude' => '31.5204', 'company.longitude' => '74.3587']);

        $this->actingAs($superAdmin)->put('/settings/company', [
            'name' => 'Renamed Co',
            'currency' => 'AED',
            'timezone' => 'Asia/Karachi',
            'date_format' => 'd/m/Y',
        ])->assertSessionHasNoErrors();

        Setting::flushCache();

        $this->assertSame('Renamed Co', settings('company.name'));
        $this->assertSame('Asia/Karachi', settings('company.timezone'));
        $this->assertSame('31.5204', (string) settings('company.latitude'), 'location preserved by profile form');
    }

    public function test_visit_can_be_started_without_browser_geolocation(): void
    {
        $salesman = User::where('email', 'salesman@nexosdigital.test')->firstOrFail();
        $employeeId = Employee::where('user_id', $salesman->id)->value('id');

        $visit = Visit::create([
            'visit_number' => 'V-1',
            'sales_rep_id' => $employeeId,
            'status' => 'pending',
            'scheduled_at' => now()->toDateString(),
        ]);

        // No latitude/longitude in the payload at all.
        $response = $this->actingAs($salesman)->post("/visits/{$visit->id}/start");

        $response->assertSessionHasNoErrors();

        $visit->refresh();

        $this->assertSame('started', $visit->status);
        $this->assertNotNull($visit->start_lat, 'start_lat resolved from company settings');
        $this->assertNotNull($visit->start_lng, 'start_lng resolved from company settings');
    }

    public function test_visit_start_still_rejects_a_far_away_browser_location(): void
    {
        $salesman = User::where('email', 'salesman@nexosdigital.test')->firstOrFail();
        $employeeId = Employee::where('user_id', $salesman->id)->value('id');

        Setting::setMany(['company.latitude' => '31.5204', 'company.longitude' => '74.3587', 'company.radius' => '500']);
        Setting::flushCache();

        $visit = Visit::create([
            'visit_number' => 'V-2',
            'sales_rep_id' => $employeeId,
            'status' => 'pending',
            'scheduled_at' => now()->toDateString(),
        ]);

        // ~300km away from Lahore.
        $this->actingAs($salesman)
            ->post("/visits/{$visit->id}/start", ['latitude' => '34.0151', 'longitude' => '71.5249'])
            ->assertSessionHas('toasts');

        $this->assertSame('pending', $visit->fresh()->status, 'visit must not start from a mismatched location');
    }
}
