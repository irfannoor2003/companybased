<?php

namespace Tests\Feature\Census;

use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\Support\RecordFactory;
use Tests\Support\RouteSweep;
use Tests\TestCase;

/**
 * Static security posture of the route table.
 *
 * The guest sweep proves that auth-protected routes actually refuse a guest.
 * This test proves the *declaration* is correct, which catches the case the
 * runtime sweep cannot: a route that was meant to be public but carries auth,
 * or — far worse — one that is meant to be protected and does not.
 */
class RouteSecurityPostureTest extends TestCase
{
    private RouteSweep $sweep;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sweep = new RouteSweep(new RecordFactory());
    }

    /**
     * Routes that are intentionally reachable by a guest. Anything not on this
     * list and without auth middleware is a bug.
     *
     * @var array<int, string> uri => why it is public
     */
    private const PUBLICLY_REACHABLE = [
        '/' => 'Root redirects to login or dashboard.',
        'up' => 'Laravel health check for uptime monitoring.',
        'login' => 'Guest login screen.',
        'forgot-password' => 'Guest password-reset screen (see README AUTH-1).',
        'reset-password/{token}' => 'Guest password-reset form.',
        // Token-authenticated: the signed-in state is proved by the reset token
        // in the URL, not by a session, so `guest` middleware is correct here.
        'reset-password' => 'Guest password-reset submit; the signed reset token in the form proves the request.',
        'verify-email' => 'Email verification notice (see README AUTH-2).',
        'verify-email/{id}/{hash}' => 'Signed email verification link.',
        'track/{code}' => 'Public order tracking by code for the end customer.',
        'employees/attendance/qr/{code}' => 'Signed QR attendance scan for staff phones.',
        'sanctum/csrf-cookie' => 'Sanctum CSRF bootstrap for API clients.',
    ];

    public function test_every_route_is_either_public_or_auth_protected(): void
    {
        $unprotected = [];

        foreach ($this->sweep->all() as $route) {
            if ($this->sweep->requiresAuth($route['middleware'])) {
                continue;
            }

            if (isset(self::PUBLICLY_REACHABLE[$route['uri']])) {
                continue;
            }

            // Storage file serving (local disk serve) is intentionally public.
            if (str_starts_with($route['uri'], 'storage/')) {
                continue;
            }

            $unprotected[] = "{$route['method']} {$route['uri']}";
        }

        $this->assertSame(
            [],
            $unprotected,
            "Routes with no auth middleware that are not on the documented public list:\n"
            .implode("\n", $unprotected),
        );
    }

    public function test_every_documented_public_route_still_exists(): void
    {
        $uris = collect($this->sweep->all())->pluck('uri')->unique();

        foreach (array_keys(self::PUBLICLY_REACHABLE) as $uri) {
            if ($uri === 'sanctum/csrf-cookie') {
                continue; // Registered by Sanctum, not by routes/web.php.
            }

            $this->assertTrue(
                $uris->contains($uri),
                "Documented public route [{$uri}] no longer exists — update PUBLICLY_REACHABLE.",
            );
        }
    }

    /**
     * Business routes must carry a permission or role gate. A route that only
     * requires a session lets every signed-in user into that screen.
     */
    public function test_every_business_route_declares_a_permission_or_role_gate(): void
    {
        // Self-service routes where "any signed-in user" is the intended rule.
        $selfService = [
            'dashboard' => 'Every signed-in user has a dashboard.',
            'dashboard/customize' => 'Personal dashboard layout.',
            'dashboard/customize.update' => 'Personal dashboard layout.',
            'profile' => 'A user may always see and edit their own profile.',
            'profile.update' => 'A user may always edit their own profile.',
            'profile.destroy' => 'A user may always delete their own account.',
            'search' => 'Global search is available to every signed-in user.',
            'search/api' => 'Autocomplete endpoint behind the search screen.',
            'notifications/read-all' => 'Marks the acting user\'s own notifications read.',
            'notifications/{notification}/read' => 'Marks one of the acting user\'s own notifications read.',
            'logout' => 'Session teardown; being signed out is never forbidden.',
            'password.confirm' => 'Re-authentication prompt for the acting user.',
            'password.update' => 'Password change for the acting user.',
            'password' => 'Password change for the acting user (PUT /password).',
            'email/verification-notification' => 'Resends a verification mail to the acting user.',
            'verify-email' => 'Verification notice for the acting user; there is no module permission for it.',
            'verify-email/{id}/{hash}' => 'Signed verification link for the acting user.',
            'confirm-password' => 'Re-authentication prompt for the acting user.',
        ];

        $unguarded = [];

        foreach (RouteFacade::getRoutes() as $route) {
            $uri = $route->uri();
            $middleware = $route->gatherMiddleware();

            // Guest and framework routes (login, password reset, email
            // verification, health check, sanctum, the local storage disk) are
            // audited by test_every_route_is_either_public_or_auth_protected,
            // which asserts the declaration. Judging their permission gate here
            // would only restate that.
            if (! in_array('auth', $middleware, true)) {
                continue;
            }

            if (str_starts_with($uri, 'storage/')) {
                continue;
            }

            if (isset($selfService[$uri]) || str_starts_with($uri, 'employees/my-')) {
                continue;
            }

            $gated = collect($middleware)->contains(
                fn (string $m) => str_starts_with($m, 'permission:')
                    || str_starts_with($m, 'role:')
                    || str_starts_with($m, 'role_or_permission:'),
            );

            if (! $gated) {
                $unguarded[] = $route->methods()[0].' '.$uri;
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            "Business routes with no permission/role gate:\n".implode("\n", $unguarded),
        );
    }
}