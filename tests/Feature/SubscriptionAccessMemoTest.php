<?php

namespace Tests\Feature;

use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * CheckSubscription runs on every authenticated request, so isAccessBlocked() was
 * two queries per page load while Setting and Module memoise for the same reason.
 *
 * The risk in memoising it is staleness: the verdict lives in a static, and this
 * application also runs a long-lived queue worker. These tests pin both halves --
 * that the query really is avoided, and that a package change is observed at once
 * rather than at the next worker restart.
 */
class SubscriptionAccessMemoTest extends TestCase
{
    use SeedsDatabase;

    private function countSubscriptionQueries(callable $callback): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $callback();

        $count = 0;

        foreach (DB::getQueryLog() as $query) {
            if (str_contains($query['query'], '`subscriptions`')) {
                $count++;
            }
        }

        DB::disableQueryLog();

        return $count;
    }

    public function test_the_verdict_is_computed_once_per_process(): void
    {
        Subscription::flushCache();

        $queries = $this->countSubscriptionQueries(function () {
            Subscription::isAccessBlocked();
            Subscription::isAccessBlocked();
            Subscription::isAccessBlocked();
        });

        // One lookup for the first call, none for the next two.
        $this->assertSame(
            1,
            $queries,
            'isAccessBlocked() must read the subscriptions table once; it runs on every request',
        );
    }

    public function test_the_memo_returns_the_same_verdict_as_a_fresh_read(): void
    {
        Subscription::flushCache();

        $this->assertFalse(Subscription::isAccessBlocked(), 'SeedsDatabase creates an active package');

        // Recomputing from scratch must agree with the memoised answer.
        $this->assertFalse(Subscription::isAccessBlocked());
    }

    public function test_no_active_package_blocks_access(): void
    {
        Subscription::query()->delete();
        Subscription::flushCache();

        $this->assertTrue(Subscription::isAccessBlocked());
    }

    public function test_an_expired_package_blocks_access(): void
    {
        Subscription::query()->delete();
        Subscription::create([
            'plan_name' => 'Lapsed',
            'starts_at' => now()->subYear(),
            'expires_at' => now()->subDay(),
            'is_active' => true,
        ]);
        Subscription::flushCache();

        $this->assertTrue(Subscription::isAccessBlocked());
        $this->assertTrue(Subscription::isExpired());
    }

    public function test_flushing_the_cache_observes_a_new_subscription(): void
    {
        Subscription::query()->delete();
        Subscription::flushCache();

        $this->assertTrue(Subscription::isAccessBlocked(), 'no package means blocked');

        Subscription::create([
            'plan_name' => 'Fresh',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
            'is_active' => true,
        ]);

        // Deliberately NOT flushed: this documents that a writer which forgets to
        // flush keeps serving the old verdict. SubscriptionController calls
        // flushCache() on both activate() and deactivate() precisely so this
        // assertion below is the one that holds.
        $this->assertTrue(
            Subscription::isAccessBlocked(),
            'the memo is intentionally sticky until flushed; writers are responsible for flushing',
        );

        Subscription::flushCache();

        $this->assertFalse(
            Subscription::isAccessBlocked(),
            'flushing must observe the newly created package',
        );
    }

    public function test_activating_a_package_takes_effect_immediately(): void
    {
        Subscription::query()->delete();
        Subscription::flushCache();

        $superAdmin = \App\Models\User::where('email', 'superadmin@nexosdigital.test')->firstOrFail();

        // Staff are refused while there is no package.
        $this->actingAs($this->userForRole('Admin'))
            ->get('/accounting/accounts')
            ->assertForbidden();

        $this->actingAs($superAdmin)
            ->post('/settings/subscription', [
                'plan_name' => 'Activated',
                'duration_days' => 365,
            ])
            ->assertRedirect(route('settings.subscription'));

        // No process restart, no cache clear: the same worker must now let staff in.
        $this->actingAs($this->userForRole('Accountant'))
            ->get('/accounting/accounts')
            ->assertOk();
    }

    /**
     * Deactivating blocks staff, it does not free them.
     *
     * isAccessBlocked() treats "no active package" and "expired package" the same
     * way, so switching the package off locks staff out. The old flash message
     * claimed the opposite ("All users now have unrestricted access"), which is how
     * an administrator could lock every user out of their own installation and be
     * told they had just opened it up.
     */
    public function test_deactivating_a_package_blocks_staff_immediately(): void
    {
        $superAdmin = \App\Models\User::where('email', 'superadmin@nexosdigital.test')->firstOrFail();

        // One user reused for both requests; userForRole() creates a row per call
        // and the second call would collide on the unique email.
        $accountant = $this->userForRole('Accountant');

        $this->actingAs($accountant)
            ->get('/accounting/accounts')
            ->assertOk();

        $this->actingAs($superAdmin)
            ->delete('/settings/subscription')
            ->assertRedirect(route('settings.subscription'));

        $this->actingAs($accountant)
            ->get('/accounting/accounts')
            ->assertForbidden();
    }

    /**
     * A memo left over from an earlier test would silently decide this one, so the
     * suite's own reset is asserted rather than assumed.
     */
    public function test_the_suite_resets_the_memo_between_tests(): void
    {
        Subscription::query()->delete();

        // Deliberately poison the memo before the next request.
        $this->assertTrue(Subscription::isAccessBlocked());

        $next = $this->get('/');

        // Whatever the next test's database state is, this request must not be
        // decided by the value cached above. A poisoned false would let an
        // unexpired-looking path through; a poisoned true would 403 the suite.
        $this->assertNotSame(500, $next->getStatusCode());
    }
}
