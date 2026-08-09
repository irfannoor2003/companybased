<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ShareAppSettings
{
    /**
     * Share the company brand settings + auth state with every view,
     * and expose the enabled module keys to JavaScript.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->runningInConsole()) {
            $primary = (string) settings('branding.primary_color', '#4f46e5');

            view()->composer('*', function ($view) use ($primary) {
                $view->with('appBrand', [
                    'primaryColor' => $primary,
                    'primaryRgb' => hex_to_rgb($primary),
                    'primaryStrongRgb' => hex_to_rgb(darken_hex($primary, 15)),
                    'accentRgb' => hex_to_rgb(settings('branding.accent_color', '#0ea5e9')),
                    'logo' => settings('branding.logo'),
                    'favicon' => settings('branding.favicon'),
                    'companyName' => company_name(),
                ]);
            });

            \Illuminate\Support\Facades\View::share('enabledModuleKeys', \App\Models\Module::enabledKeys());
        }

        return $next($request);
    }
}
