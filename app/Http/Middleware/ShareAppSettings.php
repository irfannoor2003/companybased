<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ShareAppSettings
{
    /**
     * Apply the company timezone for the current request.
     *
     * The brand payload (`$appBrand`) and `$enabledModuleKeys` used to be shared
     * from here as well, but that duplicated the composer registered in
     * AppServiceProvider with a divergent payload. They now live in exactly one
     * place: App\Support\Branding, composed once by AppServiceProvider.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->runningInConsole()) {
            date_default_timezone_set((string) settings('company.timezone', 'UTC'));
        }

        return $next($request);
    }
}
