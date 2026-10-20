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
     *
     * This answers 403 rather than 404, and names the module. A disabled module
     * still exists and is a supported state — an administrator switched it off
     * on purpose — so "this page does not exist" sent people hunting for a typo
     * or a broken permission instead of the one action that would fix it.
     *
     * The distinction that mattered most: a role whose entire workspace is one
     * module. With Employees off, an Employee logged in to a working dashboard
     * with every attendance and leave link silently missing. They had no way to
     * tell a switched-off module from a permission they had not been granted.
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (! Module::isEnabled($module)) {
            abort(403, $this->disabledMessage($module));
        }

        return $next($request);
    }

    /**
     * The message shown to a user who reached a disabled module's route.
     */
    private function disabledMessage(string $module): string
    {
        $label = Module::labelFor($module);

        return "The \"{$label}\" module is currently disabled, so this page is unavailable. "
            .'Ask an administrator to switch it back on in Settings, then Modules.';
    }
}