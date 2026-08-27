<?php

namespace App\Http\Middleware;

use App\Models\Subscription;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSubscription
{
    /**
     * Routes that must always remain reachable even when the package is expired
     * (auth flows, the super-admin control panel, health check).
     */
    protected array $excluded = [
        'login', 'logout', 'register', 'register.*',
        'password.request', 'password.email', 'password.reset', 'password.update', 'password.confirm',
        'verification.*', 'up',
        'settings.subscription', 'settings.subscription.activate', 'settings.subscription.deactivate',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Guests are handled by the auth middleware; never block them here.
        if (! $user) {
            return $next($request);
        }

        // The super admin keeps full access so they can reactivate the package.
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        if ($this->isExcluded($request)) {
            return $next($request);
        }

        if (Subscription::isExpired()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Package expired. Please contact your administrator to reactivate.',
                ], 403);
            }

            return response()->view('errors.subscription-expired', [
                'subscription' => Subscription::current(),
            ], 403);
        }

        return $next($request);
    }

    protected function isExcluded(Request $request): bool
    {
        $name = $request->route()?->getName();

        if (! $name) {
            return false;
        }

        foreach ($this->excluded as $pattern) {
            if (str_ends_with($pattern, '*')) {
                if (str_starts_with($name, substr($pattern, 0, -1))) {
                    return true;
                }
            } elseif ($pattern === $name) {
                return true;
            }
        }

        return false;
    }
}
