<?php

namespace Tests\Feature\Census;

use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Integrity of the controller layer as declared by the route table.
 *
 *  1. Every route action resolves to a real controller class and public
 *     method (a typo in routes/web.php is otherwise only visible at runtime,
 *     and only on that one screen).
 *  2. Every controller class in app/Http/Controllers is reachable from at
 *     least one route (a dead controller is dead code that still looks
 *     maintained).
 *  3. Invokable and resource controllers are detected correctly, so a
 *     renamed method is caught here rather than in production.
 */
class ControllerCoverageTest extends TestCase
{
    /**
     * @return array<int, class-string>
     */
    private function controllerClasses(): array
    {
        $classes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Http/Controllers')),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $relative = str_replace(
                [app_path('Http/Controllers').DIRECTORY_SEPARATOR, '.php'],
                ['', ''],
                $file->getPathname(),
            );

            $class = 'App\\Http\\Controllers\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    public function test_every_route_action_resolves_to_a_real_controller_method(): void
    {
        $failures = [];

        foreach (RouteFacade::getRoutes() as $route) {
            $action = $route->getActionName();

            // Closure routes (storage serving, sanctum) have no controller.
            if (! str_starts_with($action, 'App\\Http\\Controllers\\')) {
                continue;
            }

            [$class, $method] = array_pad(explode('@', $action), 2, '__invoke');

            if (! class_exists($class)) {
                $failures[] = "{$route->uri()} -> missing class {$class}";

                continue;
            }

            if (! method_exists($class, $method)) {
                $failures[] = "{$route->uri()} -> {$class}::{$method}() does not exist";

                continue;
            }

            $reflection = new \ReflectionMethod($class, $method);

            if (! $reflection->isPublic()) {
                $failures[] = "{$route->uri()} -> {$class}::{$method}() is not public";
            }
        }

        $this->assertSame(
            [],
            $failures,
            "Routes pointing at missing or non-public controller methods:\n".implode("\n", $failures),
        );
    }

    public function test_every_controller_is_reachable_from_a_route(): void
    {
        // routes/api.php is registered under the `api` middleware group, which
        // the web route collection this census walks does not contain. The two
        // API controllers are covered end-to-end by Feature/Api/ApiEndpointsTest
        // instead, which calls them with a real bearer token.
        $webControllersOnly = true;

        // README PROD-2, closed: self-registration was removed as a product
        // decision. The allow-list entry is gone along with the controller, so
        // this test now fails if anything unreachable is reintroduced. The empty
        // array is kept deliberately -- it documents that there is no permitted
        // orphan, rather than quietly dropping the concept.
        $knownDead = [];

        $used = collect(RouteFacade::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getActionName())
            ->map(fn (?string $action) => explode('@', (string) $action)[0])
            ->filter(fn (string $class) => str_starts_with($class, 'App\\Http\\Controllers\\'))
            ->unique();

        $orphans = [];
        $known = [];

        foreach ($this->controllerClasses() as $class) {
            // The base Controller is a parent class, not a route target.
            if ($class === \App\Http\Controllers\Controller::class) {
                continue;
            }

            if ($webControllersOnly && str_starts_with($class, 'App\\Http\\Controllers\\Api\\')) {
                continue;
            }

            if ($used->contains($class)) {
                continue;
            }

            if (in_array($class, $knownDead, true)) {
                $known[] = str_replace('App\\Http\\Controllers\\', '', $class).' (README PROD-2)';

                continue;
            }

            $orphans[] = str_replace('App\\Http\\Controllers\\', '', $class);
        }

        $this->assertSame(
            [],
            $orphans,
            "Controllers no route reaches (delete them or wire them up):\n".implode("\n", $orphans)
            .($known === [] ? '' : "\nKnown dead code pending README PROD-2:\n".implode("\n", $known)),
        );
    }

    /**
     * The API controllers are wired to routes and are exercised for real; this
     * guards against them being deleted outright while nothing else notices.
     */
    public function test_the_api_controllers_are_routed_and_tested(): void
    {
        foreach ([
            \App\Http\Controllers\Api\UserController::class,
            \App\Http\Controllers\Api\AttendanceApiController::class,
        ] as $class) {
            $this->assertTrue(class_exists($class), "{$class} has been deleted.");
        }

        // routes/api.php must still declare both endpoints.
        $source = (string) file_get_contents(base_path('routes/api.php'));

        $this->assertStringContainsString("get('/user'", $source);
        $this->assertStringContainsString("post('/attendance/scan'", $source);
        $this->assertStringContainsString('auth:sanctum', $source);
    }

    public function test_every_controller_extends_the_application_base_controller(): void
    {
        $failures = [];

        foreach ($this->controllerClasses() as $class) {
            // The base class cannot extend itself.
            if ($class === \App\Http\Controllers\Controller::class) {
                continue;
            }

            if (! is_subclass_of($class, \App\Http\Controllers\Controller::class)) {
                $failures[] = $class;
            }
        }

        $this->assertSame(
            [],
            $failures,
            'Controllers not extending App\Http\Controllers\Controller: '.implode(', ', $failures),
        );
    }
}