<?php

namespace App\Providers;

use App\Listeners\UpdateLastLogin;
use App\Models\Module;
use App\Support\Branding;
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
            $view->with('appBrand', Branding::payload());
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
