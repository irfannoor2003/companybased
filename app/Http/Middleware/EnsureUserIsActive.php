<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->check()) {
            $user = auth()->user();

            $expired = $user->access_until && $user->access_until->isPast();
            $inactive = ! $user->is_active;

            if ($expired || $inactive) {
                auth()->logout();

                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->withErrors([
                    'email' => 'Your access to this account has ended. Contact an administrator.',
                ]);
            }
        }

        return $next($request);
    }
}
