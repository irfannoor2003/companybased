<?php

namespace App\Providers;

use App\Listeners\UpdateLastLogin;
use App\Models\Module;
use Illuminate\Auth\Events\Login;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerEvents();
        $this->registerPagination();

        View::composer('*', function ($view) {
            $primary = (string) (settings('branding.primary_color') ?: '#4f46e5');

            $view->with('appBrand', [
                'companyName' => settings('company.name', 'Company ERP'),
                'favicon' => settings('branding.favicon'),
                'logo' => settings('branding.logo'),
                'primaryRgb' => hex_to_rgb($primary, '79 70 229'),
                'primaryStrongRgb' => hex_to_rgb(darken_hex($primary, 12), '67 56 202'),
                'accentRgb' => hex_to_rgb((string) (settings('branding.accent_color') ?: '#0ea5e9'), '244 63 94'),
                'darkMode' => settings('branding.dark_mode', 'system'),
            ]);

            $view->with('enabledModuleKeys', Module::enabledKeys());
        });
    }

    private function registerEvents(): void
    {
        $this->app['events']->listen(Login::class, UpdateLastLogin::class);
    }

    private function registerPagination(): void
    {
        Paginator::defaultView('pagination.custom');
        Paginator::defaultSimpleView('pagination.custom');
    }
}
