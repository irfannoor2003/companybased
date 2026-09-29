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
 * CheckModule is a feature-toggle gate that 404s a whole module when it is
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

    public function test_a_disabled_module_returns_404(): void
    {
        Module::query()->where('key', 'pos')->update(['enabled' => false]);
        Module::flushCache();

        $this->actingAs($this->userForRole('Admin'))
            ->get('/pos')
            ->assertNotFound();
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
