<?php

namespace App\Providers;

use App\Listeners\UpdateLastLogin;
use App\Models\Module;
use App\Models\Setting;
use Illuminate\Auth\Events\Login;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
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

        Gate::before(function ($user, $ability) {
            return $user->isSuperAdmin() ? true : null;
        });

        View::composer('*', function ($view) {
            $view->with('appBrand', [
                'companyName' => Setting::get('company_name', 'Company ERP'),
                'favicon' => Setting::get('favicon', null),
                'logo' => Setting::get('logo', null),
                'primaryRgb' => Setting::get('color_primary', '79 70 229'), // indigo-600
                'primaryStrongRgb' => Setting::get('color_primary_strong', '67 56 202'), // indigo-700
                'accentRgb' => Setting::get('color_accent', '244 63 94'), // rose-500
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
