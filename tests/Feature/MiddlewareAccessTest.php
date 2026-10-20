<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Subscription;
use App\Models\User;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Two middleware gate every authenticated request and neither had any test.
 *
 * CheckSubscription is the more dangerous of the two: it is the licensing
 * gate, and it 403s every non-Super-Admin the moment a package lapses. Before
 * these tests its blocking branch had never been executed by the suite, because
 * SeedsDatabase always creates a live subscription.
 *
 * CheckModule is a feature-toggle gate that 403s a whole module when it is
 * switched off. The sidebar re-implements the same rule in Blade, so the two
 * can disagree and the navigation would lie.
 */
class MiddlewareAccessTest extends TestCase
{
    use SeedsDatabase;

    /**
     * Expire every subscription, which is the condition the gate exists for.
     */
    private function expireSubscription(): void
    {
        Subscription::query()->update(['expires_at' => now()->subDay()]);
    }

    private function removeSubscription(): void
    {
        Subscription::query()->delete();
    }

    public function test_a_valid_subscription_lets_a_normal_user_through(): void
    {
        $this->actingAs($this->userForRole('Admin'))
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_an_expired_subscription_blocks_a_normal_user(): void
    {
        $this->expireSubscription();

        $this->actingAs($this->userForRole('Admin'))
            ->get('/dashboard')
            ->assertForbidden();
    }

    public function test_a_missing_subscription_blocks_a_normal_user(): void
    {
        $this->removeSubscription();

        $this->actingAs($this->userForRole('Admin'))
            ->get('/dashboard')
            ->assertForbidden();
    }

    public function test_the_super_admin_bypasses_the_gate_so_they_can_reactivate(): void
    {
        $this->expireSubscription();

        $superAdmin = User::where('email', 'superadmin@nexosdigital.test')->firstOrFail();

        $this->actingAs($superAdmin)
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_the_login_page_stays_reachable_when_expired(): void
    {
        $this->expireSubscription();

        // Otherwise nobody could log back in to fix the subscription.
        $this->get('/login')
            ->assertOk();
    }

    public function test_the_subscription_control_panel_stays_reachable_when_expired(): void
    {
        $this->expireSubscription();

        $this->actingAs(User::where('email', 'superadmin@nexosdigital.test')->firstOrFail())
            ->get('/settings/subscription')
            ->assertOk();
    }

    public function test_a_json_request_gets_a_json_error_not_html(): void
    {
        $this->expireSubscription();

        $response = $this->actingAs($this->userForRole('Admin'))
            ->getJson('/dashboard');

        $response->assertForbidden();
        $response->assertJsonPath('message', 'Package expired. Please contact your administrator to reactivate.');
    }

    public function test_guests_are_not_blocked_by_the_gate(): void
    {
        $this->expireSubscription();

        $this->get('/login')->assertOk();
    }

    public function test_a_disabled_module_returns_403_naming_the_module(): void
    {
        // 404, not 403: a disabled module is a supported configuration state,
        // not a missing page. 404 sent people hunting a typo or a permission
        // they thought they had been granted, when the fix is one toggle.
        Module::query()->where('key', 'pos')->update(['enabled' => false]);
        Module::flushCache();

        $this->actingAs($this->userForRole('Admin'))
            ->get('/pos')
            ->assertForbidden()
            // escape=false: the message renders a literal " in the page source
            // as &quot;, and assertSee would otherwise escape our needle again.
            ->assertSee('The &quot;POS&quot; module is currently disabled', false);
    }

    /**
     * The label comes from the modules table, so it is the admin-facing one the
     * administrator recognises from the toggle they just clicked.
     */
    public function test_the_disabled_module_message_uses_the_stored_label(): void
    {
        Module::query()->where('key', 'capital_accounts')->update(['enabled' => false]);
        Module::flushCache();

        $this->actingAs($this->userForRole('Admin'))
            ->get('/capital-accounts/contributions')
            ->assertForbidden()
            ->assertSee('The &quot;Capital Accounts&quot; module is currently disabled', false);
    }

    /**
     * The wall QR follows the same rule as every other attendance entry point:
     * if the company switched the Employees module off, the QR sign is off too.
     * A credential that records into a feature the administrator deliberately
     * disabled makes no sense, and the 403 names the module so anyone scanning a
     * stale sign is told why nothing happens instead of staring at a blank page.
     */
    public function test_the_wall_qr_kiosk_is_gated_by_the_employees_module(): void
    {
        $employee = $this->employeeUserForRole('Employee')->employeeProfile();

        $this->assertNotNull($employee, 'precondition: the employee has a profile');

        Module::query()->where('key', 'employees')->update(['enabled' => false]);
        Module::flushCache();

        $timestamp = now()->timestamp;
        $signature = hash_hmac('sha256', $employee->employee_code.'|'.$timestamp, (string) config('app.key'));

        $this->get("/employees/attendance/qr/{$employee->employee_code}?s={$signature}&t={$timestamp}")
            ->assertForbidden()
            ->assertSee('The &quot;Employees&quot; module is currently disabled', false);

        $this->assertNull(
            $employee->attendanceRecords()->latest('id')->first(),
            'no scan may be recorded while the module is off',
        );
    }

    /**
     * With the module on, an unsigned scan is still rejected by the signed,
     * short-lived URL — the signature, not the module, is the credential.
     */
    public function test_the_wall_qr_kiosk_still_rejects_an_unsigned_scan(): void
    {
        $employee = $this->employeeUserForRole('Employee')->employeeProfile();

        $timestamp = now()->timestamp;

        $this->get("/employees/attendance/qr/{$employee->employee_code}?s=forged&t={$timestamp}")
            ->assertRedirect(route('login'));

        $this->assertNull(
            $employee->attendanceRecords()->latest('id')->first(),
            'a forged scan must not record attendance',
        );
    }

    public function test_an_enabled_module_is_reachable(): void
    {
        Module::query()->where('key', 'pos')->update(['enabled' => true]);
        Module::flushCache();

        $this->actingAs($this->userForRole('Admin'))
            ->get('/pos')
            ->assertOk();
    }

    public function test_disabling_one_module_leaves_the_others_alone(): void
    {
        Module::query()->where('key', 'pos')->update(['enabled' => false]);
        Module::flushCache();

        $this->actingAs($this->userForRole('Admin'))
            ->get('/catalog/products')
            ->assertOk();
    }

    /**
     * Module state is cached for an hour, so a toggle that does not invalidate
     * the cache would not take effect until the next request an hour later.
     */
    public function test_toggling_a_module_invalidates_its_cache(): void
    {
        $this->assertTrue(Module::isEnabled('pos'));

        Module::query()->where('key', 'pos')->update(['enabled' => false]);
        Module::flushCache();

        $this->assertFalse(Module::isEnabled('pos'), 'the cached key list must reflect the toggle');
    }
}
