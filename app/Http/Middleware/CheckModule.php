<?php

namespace App\Http\Middleware;

use App\Models\Module;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckModule
{
    /**
     * Gate a route group behind an enabled module.
     * Usage: ->middleware('module:sales')
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (! Module::isEnabled($module)) {
            abort(404);
        }

        return $next($request);
    }
}
