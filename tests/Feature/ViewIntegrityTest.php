<?php

namespace Tests\Feature;

use App\Support\Permissions;
use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Blade is not compiled or type-checked, so two whole classes of mistake survive
 * until a user opens the affected page:
 *
 *  1. A route name that does not exist. route() throws RouteNotFoundException, so
 *     the page 500s. This is not hypothetical: documents/show.blade.php referenced
 *     "quotes.send_email" and "purchase_orders.send_email" when the real names are
 *     module-prefixed, which made the quote and purchase-order pages return 500.
 *
 *  2. A permission string that is not in the registry. can() simply returns false,
 *     so the guarded button or link is withheld from every user and the feature it
 *     belongs to is silently unreachable. pos/receipts/index.blade.php checked
 *     "pos.receipts.export" and "pos.receipts.show"; the registry only grants
 *     view/create/print/reprint, so POS receipts had no export button and no way
 *     to open a receipt at all.
 *
 * Both are cheap to detect statically, so they are asserted here rather than
 * discovered by a customer.
 */
class ViewIntegrityTest extends TestCase
{
    /**
     * Route names that are legitimately absent depending on configuration.
     *
     * @var array<string, string>
     */
    private const CONDITIONAL_ROUTES = [
        // Defined only when config('app.enable_registration') is true, which
        // defaults to false. The view ships with the app either way.
        'register' => 'routes/auth.php gates the register routes behind config("app.enable_registration"), which defaults to false.',
    ];

    /**
     * Permission strings that are legitimately absent.
     *
     * @var array<string, string>
     */
    private const CONDITIONAL_PERMISSIONS = [
        // Guarded by @can('...') on a route that is itself only registered when
        // registration is enabled.
        'settings.notification-rules.view' => 'unused placeholder, kept for symmetry with the other settings tabs',
    ];

    /**
     * @return array<string, string>  relative path => file contents
     */
    private function bladeFiles(): array
    {
        $files = [];
        $directory = new RecursiveDirectoryIterator(resource_path('views'));

        foreach (new RecursiveIteratorIterator($directory) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $files[str_replace('\\', '/', str_replace(resource_path(), '', $file->getPathname()))]
                = file_get_contents($file->getPathname());
        }

        $this->assertNotEmpty($files, 'no Blade files were found; the scan is broken');

        return $files;
    }

    /**
     * @return array<string, string>  file => comma-separated offenders
     */
    private function offenders(callable $extract, callable $isValid): array
    {
        $found = [];

        foreach ($this->bladeFiles() as $relative => $source) {
            $bad = [];

            foreach (array_unique($extract($source)) as $candidate) {
                if ($isValid($candidate)) {
                    continue;
                }
                $bad[] = $candidate;
            }

            if ($bad !== []) {
                sort($bad);
                $found[$relative] = implode(', ', $bad);
            }
        }

        return $found;
    }

    /**
     * @return array<string, string>
     */
    private function report(array $found, string $consequence): string
    {
        if ($found === []) {
            return '';
        }

        $lines = [];

        foreach ($found as $file => $list) {
            $lines[] = "  {$file}: {$list}";
        }

        return sprintf(
            "The following reference names that do not exist.\n%s\n\n%s\n\n"
            ."If a listed name is legitimately conditional, add it to the allow-list with a comment "
            ."explaining the condition. Otherwise fix the reference.",
            implode("\n", $lines),
            $consequence,
        );
    }

    public function test_every_route_name_used_in_a_view_exists(): void
    {
        $found = $this->offenders(
            // route('x') and <x-export route="x">.
            //
            // The negative lookbehind excludes `->route('x')`, which is how
            // reset-password.blade.php reads the token parameter
            // ($request->route('token')) rather than generating a URL. Dynamic
            // names (route($var)) are not literals and are out of scope.
            fn (string $source): array => array_merge(
                $this->capture($source, "/(?<![->\w])route\(\s*'([^']+)'/"),
                $this->capture($source, '/route\s*=\s*"([^"]+)"/'),
            ),
            fn (string $name): bool => Route::has($name)
                || isset(self::CONDITIONAL_ROUTES[$name]),
        );

        $this->assertSame([], $found, $this->report(
            $found,
            'route() throws RouteNotFoundException for an unknown name, so the page returns 500 as soon '
            .'as it is opened.',
        ));
    }

    public function test_every_permission_checked_in_a_view_exists_in_the_registry(): void
    {
        $registry = array_map('strtolower', Permissions::all());

        // Compare case-insensitively: the registry is lowercase, and a stray
        // capital would make can() fail silently for everyone.
        $found = $this->offenders(
            fn (string $source): array => $this->capture(
                $source,
                "/(?:->can\(|@can\(|@canany\(|@cannot\()\s*'([^']+)'/",
            ),
            fn (string $permission): bool => in_array(strtolower($permission), $registry, true)
                || isset(self::CONDITIONAL_PERMISSIONS[strtolower($permission)]),
        );

        $this->assertSame([], $found, $this->report(
            $found,
            'can() returns false for a permission that is not in the registry, so the button or link it '
            .'guards is withheld from every user and the feature is unreachable. The route middleware '
            .'uses the same strings, so check which one is wrong.',
        ));
    }

    /**
     * The sidebar and the settings tab bar are driven by config/nav.php rather than
     * by markup in a view, so they need checking separately.
     */
    public function test_every_permission_in_the_nav_config_exists(): void
    {
        $registry = array_map('strtolower', Permissions::all());

        $source = file_get_contents(config_path('nav.php'));

        $this->assertIsString($source, 'config/nav.php could not be read');

        $bad = [];

        foreach (array_unique($this->capture($source, "/'permission'\s*=>\s*'([^']+)'/")) as $permission) {
            if (! in_array(strtolower($permission), $registry, true)) {
                $bad[] = $permission;
            }
        }

        sort($bad);

        $this->assertSame([], $bad, sprintf(
            "config/nav.php gates navigation on permissions that are not in the registry:\n  %s\n\n"
            .'A nav item gated on a non-existent permission is never rendered, so the page is unreachable '
            .'from the sidebar even though the route works.',
            implode("\n  ", $bad),
        ));
    }

    /**
     * Every route the sidebar can link to must resolve. A nav entry pointing at a
     * missing route name is a dead menu item that throws when clicked.
     */
    public function test_every_route_in_the_nav_config_exists(): void
    {
        $source = file_get_contents(config_path('nav.php'));

        $this->assertIsString($source, 'config/nav.php could not be read');

        $bad = [];

        foreach (array_unique($this->capture($source, "/'route'\s*=>\s*'([^']+)'/")) as $name) {
            if (! Route::has($name)) {
                $bad[] = $name;
            }
        }

        sort($bad);

        $this->assertSame([], $bad, sprintf(
            "config/nav.php points at route names that do not exist:\n  %s\n\n"
            .'These render as menu entries that throw RouteNotFoundException when clicked.',
            implode("\n  ", $bad),
        ));
    }

    /**
     * @return array<int, string>
     */
    private function capture(string $source, string $pattern): array
    {
        preg_match_all($pattern, $source, $matches);

        return $matches[1] ?? [];
    }
}
