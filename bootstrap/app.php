<?php

use App\Http\Middleware\CheckModule;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ShareAppSettings;
use Dotenv\Dotenv;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$basePath = dirname(__DIR__);

// The app depends on APP_KEY and the configured database connection. If the
// environment file can't be read for a single request (e.g. a transient
// antivirus lock on Windows), the app otherwise boots with a null encryption
// key and the default sqlite connection, producing MissingAppKeyException /
// "no such table: settings" errors on arbitrary requests. Eagerly load the
// file with a short retry loop so one bad read cannot take the app down.
if (is_file($basePath.'/.env') && ! is_file($basePath.'/bootstrap/cache/config.php')) {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        try {
            Dotenv::createImmutable($basePath)->safeLoad();
        } catch (Throwable) {
            // Fall through to the retry below.
        }

        if (($_ENV['APP_KEY'] ?? getenv('APP_KEY')) !== false) {
            break;
        }

        usleep(100_000);
    }

    if (($_ENV['APP_KEY'] ?? getenv('APP_KEY')) === false) {
        throw new RuntimeException(
            'The environment file could not be loaded for this request; APP_KEY is missing. '
            .'Check that '.$basePath.'/.env exists and is readable.'
        );
    }
}

return Application::configure(basePath: $basePath)
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            ShareAppSettings::class,
            EnsureUserIsActive::class,
        ]);

        $middleware->alias([
            'module' => CheckModule::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
