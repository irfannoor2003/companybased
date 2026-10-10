<?php

namespace Tests\Feature\Census;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\View as ViewFacade;
use Tests\TestCase;

/**
 * Static integrity of the view layer.
 *
 * The front-end counterpart of the route census: instead of executing
 * controllers it inspects every Blade file and every view reference.
 *
 *  1. Every Blade file compiles (Blade syntax errors are fatal for the page
 *     that renders it, and some blades — PDFs, emails, partials — are never
 *     reached by a normal GET sweep).
 *  2. Every view('...') call in app/ resolves to a real Blade file.
 *  3. Every @include/@includeIf in every Blade resolves.
 *  4. Every route('...') name used in any Blade is a registered route.
 *  5. Every <x-...> component used anywhere resolves to a class component or
 *     an anonymous component view.
 *  6. Directive pairs are balanced — Blade compiles malformed structures to
 *     malformed output without complaint, so counts are checked directly.
 */
class ViewIntegrityTest extends TestCase
{
    /**
     * @return array<int, string> absolute blade paths
     */
    private function bladeFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views')),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return array<int, string>
     */
    private function phpFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    public function test_every_blade_file_compiles(): void
    {
        $failures = [];
        $compiled = 0;

        foreach ($this->bladeFiles() as $path) {
            try {
                Blade::compileString(file_get_contents($path));
                $compiled++;
            } catch (\Throwable $e) {
                $relative = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $path);
                $failures[] = "{$relative}: {$e->getMessage()}";
            }
        }

        $this->assertSame(
            [],
            $failures,
            "Blade files that do not compile (".count($failures)."):\n".implode("\n", $failures),
        );

        $this->assertGreaterThan(300, $compiled, 'The suite must see the full view tree.');
    }

    public function test_every_view_call_in_application_code_resolves(): void
    {
        $failures = [];
        $checked = 0;

        foreach ($this->phpFiles(app_path()) as $path) {
            $source = file_get_contents($path);

            preg_match_all("/view\(\s*'([^']+)'/", $source, $matches);

            foreach ($matches[1] as $viewName) {
                $checked++;

                if (! ViewFacade::exists($viewName)) {
                    $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
                    $failures[] = "{$relative} renders view [{$viewName}] which does not exist.";
                }
            }
        }

        $this->assertSame(
            [],
            $failures,
            "Missing views referenced by application code:\n".implode("\n", $failures),
        );

        $this->assertGreaterThan(50, $checked, 'Expected to find many view() calls to check.');
    }

    public function test_every_include_in_every_blade_resolves(): void
    {
        $failures = [];
        $checked = 0;

        foreach ($this->bladeFiles() as $path) {
            $source = file_get_contents($path);
            $relative = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $path);

            // Dynamic includes (variable names) cannot be checked statically.
            preg_match_all("/@include(?:If|When|Unless)?\(\s*'([^']+)'/", $source, $matches);

            foreach ($matches[1] as $viewName) {
                $checked++;

                if (! ViewFacade::exists($viewName)) {
                    $failures[] = "{$relative} includes [{$viewName}] which does not exist.";
                }
            }
        }

        $this->assertSame(
            [],
            $failures,
            "Missing @include targets:\n".implode("\n", $failures),
        );

        $this->assertGreaterThan(20, $checked);
    }

    public function test_every_route_name_used_in_a_blade_is_registered(): void
    {
        // README PROD-2, closed: both the route and the blade that referenced it
        // have been deleted, so there is nothing left to allow. The empty list is
        // kept deliberately -- any future route('register') now fails the build
        // rather than being absorbed by an allow-list.
        $knownDeadLinks = [];

        $registered = collect(RouteFacade::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter()
            ->all();

        $failures = [];
        $known = [];
        $checked = 0;

        foreach ($this->bladeFiles() as $path) {
            $source = file_get_contents($path);
            $relative = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $path);

            // `route('name')` is the helper; `$request->route('token')` and
            // `Route::route(...)` read a route PARAMETER off the current
            // request, so the leading -> must be excluded or every parameter
            // name is reported as a missing route.
            preg_match_all("/(?<!->)\broute\(\s*'([^']+)'/", $source, $matches);

            foreach ($matches[1] as $name) {
                $checked++;

                if (in_array($name, $registered, true)) {
                    continue;
                }

                if (in_array($name, $knownDeadLinks, true)) {
                    $known[] = "{$relative} -> {$name} (README PROD-2)";

                    continue;
                }

                $failures[] = "{$relative} links to route [{$name}] which is not registered.";
            }
        }

        $this->assertSame(
            [],
            $failures,
            "Blades referencing unknown routes (".count($failures)."):\n".implode("\n", $failures)
            .($known === [] ? '' : "\nKnown dead links pending README PROD-2:\n".implode("\n", $known)),
        );

        $this->assertGreaterThan(100, $checked, 'Expected many route() references in the view layer.');
    }

    public function test_every_component_tag_used_in_a_blade_resolves(): void
    {
        $missing = [];
        $checked = 0;

        foreach ($this->bladeFiles() as $path) {
            $source = file_get_contents($path);
            $relative = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $path);

            // <x-foo.bar ...> / <x-foo-bar ...>; <x-slot> is Blade machinery.
            preg_match_all('/<x-([a-z0-9\-\.]+)(?=[\s\/>])/', strtolower($source), $matches);

            foreach (array_unique($matches[1]) as $tag) {
                if ($tag === 'slot') {
                    continue;
                }

                $checked++;

                $kebab = str_replace('.', '-', $tag);
                $studly = str_replace(' ', '', ucwords(str_replace('-', ' ', $kebab)));

                $resolves = class_exists("App\\View\\Components\\{$studly}")
                    || ViewFacade::exists('components.'.str_replace('.', '/', $tag));

                if (! $resolves) {
                    $missing[] = "{$relative} uses <x-{$tag}> which resolves to neither "
                        ."App\\View\\Components\\{$studly} nor components/{$tag}.blade.php";
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Unresolvable <x-> components (".count($missing)."):\n".implode("\n", $missing),
        );

        $this->assertGreaterThan(50, $checked);
    }

    /**
     * Blade compiles malformed structures without complaint, so directive
     * pairs are counted directly: an @if without @endif renders fine right up
     * until the branch is taken.
     */
    public function test_blade_directive_pairs_are_balanced(): void
    {
        $pairs = [
            '@if' => '@endif',
            '@foreach' => '@endforeach',
            '@forelse' => '@endforelse',
            '@for' => '@endfor',
            '@while' => '@endwhile',
            '@section' => '@endsection',
            '@push' => '@endpush',
            '@once' => '@endonce',
            '@error' => '@enderror',
            '@auth' => '@endauth',
            '@guest' => '@endguest',
            '@isset' => '@endisset',
            '@empty' => '@endempty',
            '@verbatim' => '@endverbatim',
            '@production' => '@endproduction',
        ];

        $failures = [];

        foreach ($this->bladeFiles() as $path) {
            $source = file_get_contents($path);
            $relative = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $path);

            foreach ($pairs as $open => $close) {
                $counts = $this->directiveCounts($source, $open, $close);

                if ($counts[$open] !== $counts[$close]) {
                    $failures[] = "{$relative}: {$open} x{$counts[$open]} but {$close} x{$counts[$close]}";
                }
            }

            // `@php` has three shapes, only one of which needs an @endphp:
//   @php($expr)          expression form, self-contained
//   @php $x = 1; @endphp one-liner, self-contained
//   @php ... @endphp     block form
            $expressions = preg_replace('/@php[ \t]*\([^)]*\)/', '', $source);

            $oneLiners = preg_replace(
                '/@php[ \t]+[^@\n]*;[ \t]*(?:@endphp)?/',
                '',
                (string) $expressions,
            );

            $blocks = preg_match_all('/@php(?![a-z])/i', (string) $oneLiners);
            $endphp = preg_match_all('/@endphp(?![a-z])/i', (string) $oneLiners);

            if ($blocks !== $endphp) {
                $failures[] = "{$relative}: @php block x{$blocks} but @endphp x{$endphp}";
            }
        }

        $this->assertSame(
            [],
            $failures,
            "Unbalanced Blade directives:\n".implode("\n", $failures),
        );
    }

    /**
     * Occurrences of a directive pair, with @forelse blocks excluded first.
     *
     * `@empty` is the else-branch of `@forelse`, closed by `@endforelse` and
     * never by `@endempty`. Counting it naively reports every @forelse in the
     * application as an unclosed @empty.
     *
     * @return array<string, int>
     */
    private function directiveCounts(string $source, string $open, string $close): array
    {
        if ($open === '@empty') {
            $source = (string) preg_replace('/@forelse\b.*?@endforelse/s', '', $source);
        }

        return [
            $open => preg_match_all('/'.preg_quote($open, '/').'(?![a-z])/i', $source),
            $close => preg_match_all('/'.preg_quote($close, '/').'(?![a-z])/i', $source),
        ];
    }
}

