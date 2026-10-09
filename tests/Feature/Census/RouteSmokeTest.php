<?php

namespace Tests\Feature\Census;

use App\Models\Employee;
use App\Models\Module;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\SeedsDatabase;
use Tests\Support\RecordFactory;
use Tests\Support\RouteSweep;
use Tests\TestCase;

/**
 * Whole-application HTTP census.
 *
 * One seeded run walks every route the application registers and asserts the
 * one property that must never be violated: an authorised user gets a page
 * (2xx/3xx) and nobody — authorised or not — ever gets a 500.
 *
 * Three sweeps, each in its own test method (own transaction, own seeding):
 *
 *  1. GET sweep      — Admin -> Super Admin -> Employee fallback chain. 403
 *                      from one persona is retried with the next; a 404 or
 *                      500 never is (those do not depend on who is asking).
 *                      Every skip is enumerated in RouteSweep with a reason.
 *  2. Guest sweep    — every auth-protected GET, unauthenticated: must never
 *                      return 200 (a content leak) or 500.
 *  3. Write sweep    — every POST/PUT/PATCH/DELETE with an empty payload:
 *                      must validate (302), deny (403), or 404 — never 500.
 *                      DELETE gets a fresh fixture row so deleting cannot
 *                      invalidate the cached row other routes point at.
 *
 * Failures are collected, not thrown on first contact, so a single run
 * produces the complete bug list for the README instead of one bug per run.
 */
class RouteSmokeTest extends TestCase
{
    use SeedsDatabase {
        // The class-level setUp() below replaces the trait's; alias the
        // trait's version so seeding still runs (it calls parent::setUp(),
        // seeds the database and creates the test subscription).
        setUp as private seedCensusDatabase;
    }

    private RouteSweep $sweep;

    protected function setUp(): void
    {
        $this->seedCensusDatabase();

        // Every module ON: the module-disabled path is MiddlewareAccessTest's
        // job; this sweep measures "can an authorised user load the page".
        Module::query()->update(['enabled' => true]);
        Module::flushCache();

        // Defence in depth against the skip list drifting: every module is
        // forced on and the package is re-armed before the sweep, so a single
        // state-mutating route that slips through cannot cascade into a
        // sweep-wide mass 403.
        Subscription::current()
            ?? Subscription::create(['plan_name' => 'Sweep', 'starts_at' => now(), 'expires_at' => now()->addYear(), 'is_active' => true]);
        Subscription::query()->update(['is_active' => true, 'expires_at' => now()->addYear()]);
        Subscription::flushCache();

        Storage::fake('backups');

        $this->sweep = new RouteSweep(new RecordFactory());
    }

    /**
     * Sweep 1: every GET route must render for the persona that may see it.
     *
     * The persona chain mirrors the role design: Admin holds every business
     * permission, Super Admin holds the settings.* permissions Admin lacks,
     * Employee holds the self-service attendance/leave permissions behind the
     * `employee` middleware. A 403 falls through to the next persona; a 404 or
     * 500 does not (those do not depend on who is asking) and is reported
     * immediately for that persona list.
     */
    public function test_every_get_route_renders_for_an_authorised_user(): void
    {
        $chain = [
            'Admin' => $this->userForRole('Admin'),
            'Super Admin' => User::where('email', 'superadmin@nexosdigital.test')->firstOrFail(),
            'Employee' => $this->employeeUserForRole('Employee'),
        ];

        // Routes whose legitimate outcome for a bare fixture is not a 200. Each
        // entry says WHY, so a skip can never hide a regression.
        $expectedNotFound = [
            // Laravel's local-file serve route answers 403/404 for a path that
            // is not on disk; it must never 500.
            'storage/{path}' => [404, 403],
        ];

        $failures = [];
        $rendered = 0;

        foreach ($this->sweep->gets() as $route) {
            $failure = null;

            foreach ($chain as $persona => $user) {
                try {
                    $uri = '/'.ltrim($this->sweep->fillUri($route['uri'], $user), '/');
                } catch (\Throwable $e) {
                    $failures[] = "FIXTURE {$route['method']} {$route['uri']} [{$persona}]: {$e->getMessage()}";

                    continue 2;
                }

                $response = $this->actingAs($user)->get($uri);

                $status = $response->getStatusCode();

                // Documented non-200 outcomes are checked before anything else,
                // so a documented 403 is never mistaken for a permission wall.
                if (isset($expectedNotFound[$route['uri']])
                    && in_array($status, (array) $expectedNotFound[$route['uri']], true)) {
                    $rendered++;
                    $failure = null;

                    break;
                }

                if ($status >= 200 && $status < 400) {
                    $rendered++;
                    $failure = null;

                    break;
                }

                if ($status === 403) {
                    // No permission for this persona — try the next one. The
                    // reason is carried forward so a route nobody can reach is
                    // reported with what actually refused it.
                    $detail = $response->exception
                        ? ' ['.$response->exception->getMessage().']'
                        : '';

                    $failure = "403 for every persona{$detail}";

                    continue;
                }

                // 404/4xx-from-binding/5xx do not improve with another persona.
                $detail = $response->exception
                    ? ' ('.$response->exception->getMessage().')'
                    : '';

                $failure = "{$status}{$detail} as {$persona}";

                break;
            }

            if ($failure !== null) {
                $failures[] = "GET {$route['uri']} => {$failure}";
            }
        }

        $this->assertSame(
            [],
            $failures,
            "GET sweep failures (".count($failures)."):\n".implode("\n", $failures),
        );

        $this->assertGreaterThan(300, $rendered, 'The GET sweep should exercise the whole application.');
    }

    /**
     * Sweep 2: every auth-protected page must refuse an unauthenticated guest.
     *
     * A 200 here is a content leak (the page rendered without a session); a
     * 500 is a crash. Redirects to the login page and JSON 401s are the two
     * correct refusals.
     */
    public function test_no_auth_protected_page_renders_for_a_guest(): void
    {
        $fixtureUser = $this->userForRole('Admin');

        $failures = [];
        $refused = 0;

        foreach ($this->sweep->authedGets() as $route) {
            try {
                // Fixtures may need a user context to resolve; the guest is
                // never signed in — the value only exists so binding can be
                // built if middleware order ever reaches it.
                $uri = '/'.ltrim($this->sweep->fillUri($route['uri'], $fixtureUser), '/');
            } catch (\Throwable $e) {
                $failures[] = "FIXTURE GET {$route['uri']}: {$e->getMessage()}";

                continue;
            }

            $response = $this->get($uri);
            $status = $response->getStatusCode();

            if ($status === 200) {
                $failures[] = "GET {$route['uri']} => 200 for a GUEST (content leak)";

                continue;
            }

            if (in_array($status, [301, 302, 401, 403], true)) {
                $refused++;

                continue;
            }

            $detail = $response->exception ? ' ('.$response->exception->getMessage().')' : '';
            $failures[] = "GET {$route['uri']} => {$status}{$detail} for a GUEST";
        }

        $this->assertSame(
            [],
            $failures,
            "Guest sweep failures (".count($failures)."):\n".implode("\n", $failures),
        );

        $this->assertGreaterThan(200, $refused, 'Most of the application must sit behind auth.');
    }

    /**
     * Sweep 3: every mutating route must handle an empty payload without a 500.
     *
     * A browser double-submit, a stale bookmark, or an API client with a bug
     * all land here. Well-built endpoints answer with a validation redirect
     * (302) or a permission denial (403); only a missing rule or an unguarded
     * null dereference answers with a 500. DELETE verbs receive a fresh
     * fixture row per call so deleting cannot invalidate the cached row that
     * a later route still points at.
     *
     * Routes with side effects too big for a sweep (mysqldump shells, module
     * and subscription toggles, logout) are enumerated with reasons in
     * RouteSweep::SKIP_WRITE and covered by their dedicated test files.
     */
    public function test_every_write_route_survives_an_empty_payload(): void
    {
        $personas = [
            $this->userForRole('Admin'),
            User::where('email', 'superadmin@nexosdigital.test')->firstOrFail(),
            $this->employeeUserForRole('Employee'),
            $this->userForRole('HR'),
            $this->userForRole('Accountant'),
            $this->userForRole('Salesman'),
            $this->userForRole('Inventory Manager'),
        ];

        // Routes whose legitimate outcome is a refusal the sweep cannot avoid.
        //
        // employees/my-leave/{leave}/cancel is scoped to the signed-in user's
        // OWN leave request, and a sweep fixture belongs to nobody; 403 is the
        // correct answer and is exactly what must keep happening.
        $expectedWriteStatus = [
            'employees/my-leave/{leave}/cancel' => [403],
            // Laravel's local-file serve route refuses writes outright.
            'storage/{path}' => [403, 405],
        ];

        $failures = [];
        $handled = 0;

        foreach ($this->sweep->writes() as $route) {
            $isDelete = $route['method'] === 'DELETE';

            try {
                // The persona is chosen from the route's own permission
                // middleware rather than guessed by trial: "try each persona
                // until one is not refused" reports the LAST refusal as the
                // verdict, which mislabels a route that Admin can reach.
                $user = $this->personaFor($route, $personas);
                $uri = '/'.ltrim($this->sweep->fillUri($route['uri'], $user, $isDelete), '/');
            } catch (\Throwable $e) {
                $failures[] = "FIXTURE {$route['method']} {$route['uri']}: {$e->getMessage()}";

                continue;
            }

            // actingAs() sets the acting user on the guard instance; a previous
            // request may have run middleware that logged out and invalidated
            // the session, so the guard is reset before each new persona.
            $this->app['auth']->forgetGuards();

            $response = $this->actingAs($user)->call($route['method'], $uri);
            $status = $response->getStatusCode();

            if (isset($expectedWriteStatus[$route['uri']])
                && in_array($status, (array) $expectedWriteStatus[$route['uri']], true)) {
                $handled++;

                continue;
            }

            if (in_array($status, [419, 500, 502, 503], true)) {
                $detail = $response->exception
                    ? ' ('.$response->exception->getMessage().')'
                    : '';

                $failures[] = "{$route['method']} {$route['uri']} => {$status}{$detail}";

                continue;
            }

            if ($status === 403) {
                $permission = $this->sweep->requiredPermission($route['middleware']);

                $probe = array_map(
                    fn (\App\Models\User $p): string => $p->roles->pluck('name')->implode('+')
                        .':'.((int) $p->can($permission ?? '__none__')),
                    $personas,
                );

                $holder = $permission
                    ? 'no test persona holds '.$permission.' ['.implode(' ', $probe).']'
                    : 'route is not permission-gated but still refuses';

                $failures[] = "{$route['method']} {$route['uri']} => 403 ({$holder})";

                continue;
            }

            // 2xx/3xx = validated or succeeded; 404 = binding legitimately
            // absent; 422 = JSON validation. All non-crashes.
            $handled++;
        }

        $this->assertSame(
            [],
            $failures,
            "Write sweep failures (".count($failures)."):\n".implode("\n", $failures),
        );

        $this->assertGreaterThan(200, $handled, 'The write sweep should exercise most mutating routes.');
    }

    /**
     * The first persona that actually holds the route's permission.
     *
     * @param  array{method: string, uri: string, name: string|null, middleware: array<int, string>}  $route
     * @param  array<int, \App\Models\User>  $personas
     */
    private function personaFor(array $route, array $personas): \App\Models\User
    {
        $permission = $this->sweep->requiredPermission($route['middleware']);

        // Employee self-service routes act on the employee profile linked to
        // the signed-in user, not on the user row. Asking a user with no
        // Employee record to call one returns 403 for a correct reason and
        // proves nothing, so such routes are driven by a persona that has a
        // profile.
        if (str_starts_with($route['uri'], 'employees/my-')) {
            foreach ($personas as $user) {
                if (($permission === null || $user->can($permission))
                    && Employee::where('user_id', $user->id)->exists()) {
                    return $user;
                }
            }
        }

        if ($permission !== null) {
            foreach ($personas as $user) {
                if ($user->can($permission)) {
                    return $user;
                }
            }
        }

        return $personas[0];
    }

    /**
     * Every skip in the census is reported, so the README's skip list can be
     * regenerated from the suite instead of drifting by hand.
     */
    public function test_the_sweep_skip_lists_are_documented(): void
    {
        $report = $this->sweep->skipReport();

        $this->assertNotEmpty($report);

        foreach ($report as $uri => $reason) {
            $this->assertNotSame('', trim($reason), "Skip [{$uri}] must carry a written reason.");
            $this->assertGreaterThan(30, strlen($reason), "Skip [{$uri}] reason is too vague to audit.");
        }
    }
}
