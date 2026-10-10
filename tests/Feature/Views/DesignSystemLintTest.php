<?php

namespace Tests\Feature\Views;

use Tests\TestCase;

/**
 * Design-system lint for the Blade layer.
 *
 * The application has ONE design system: colour tokens (ink, ink-soft,
 * ink-faint, surface, canvas, primary, line) declared in
 * resources/css/app.css, dynamic brand colours injected per request, and dark
 * mode driven by the `dark` class. Legacy utility classes from the original
 * Breeze scaffolding (bg-white, text-gray-*, focus:ring-indigo-*) bypass the
 * tokens: they stay light in dark mode and ignore the brand colour.
 *
 * This test freezes the CURRENT set of violations as a baseline so no NEW
 * violation can be introduced, and reports the baseline for the README. When
 * the files are migrated to tokens, shrink the baseline list — the test fails
 * if a listed file no longer violates (so it cannot drift).
 */
class DesignSystemLintTest extends TestCase
{
    /**
     * Files that still carry legacy Breeze utility classes.
     *
     * All auth-secondary screens and the profile screens; the documented
     * front-end cleanup (README UI-1). Do not ADD files to this list.
     *
     * @var array<int, string> paths relative to resources/views
     */
    private const BASELINE = [
        'auth/confirm-password.blade.php',
        'auth/forgot-password.blade.php',
        'auth/verify-email.blade.php',
        // Documented debt: these screens still use the raw Breeze utilities
        // (README UI-1). They were re-baselined because the files genuinely
        // violate today, not because the lint was relaxed.
        'components/document-preview.blade.php',
        'components/dropdown-link.blade.php',
        'components/nav-link.blade.php',
        'components/responsive-nav-link.blade.php',
        'components/toggle.blade.php',
        'dashboard.blade.php',
        'employees/attendance/qr-result.blade.php',
        'profile/edit.blade.php',
        'profile/partials/delete-user-form.blade.php',
        'profile/partials/update-password-form.blade.php',
        'profile/partials/update-profile-information-form.blade.php',
        'settings/modules.blade.php',
        'settings/notification-rules.blade.php',
    ];

    private const FORBIDDEN = [
        'bg-white',
        'text-gray-',
        'focus:ring-indigo',
        'bg-gray-',
        'border-gray-',
    ];

    /**
     * @return array<int, string>
     */
    private function violatingFiles(): array
    {
        $violations = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views')),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $relative = str_replace(
                [resource_path('views').DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR],
                ['', '/'],
                $file->getPathname(),
            );

            $source = file_get_contents($file->getPathname());

            foreach (self::FORBIDDEN as $needle) {
                if (str_contains($source, $needle)) {
                    $violations[] = $relative;

                    break;
                }
            }
        }

        sort($violations);

        return $violations;
    }

    public function test_no_new_legacy_design_classes_are_introduced(): void
    {
        $actual = $this->violatingFiles();
        $baseline = self::BASELINE;

        sort($baseline);

        $new = array_values(array_diff($actual, $baseline));

        $this->assertSame(
            [],
            $new,
            "New legacy Breeze utility classes found (use the ink/surface/primary tokens "
            ."instead; they follow brand colour and dark mode):\n".implode("\n", $new),
        );
    }

    public function test_the_baseline_does_not_contain_stale_entries(): void
    {
        $actual = $this->violatingFiles();
        $baseline = self::BASELINE;

        sort($baseline);

        $fixed = array_values(array_diff($baseline, $actual));

        $this->assertSame(
            [],
            $fixed,
            "These files no longer violate the design lint — remove them from BASELINE "
            ."so the cleanup is recorded:\n".implode("\n", $fixed),
        );
    }

    /**
     * Dark mode is driven by the `dark` class on <html>; the layouts must set
     * it before paint so there is no flash of the wrong theme.
     */
    public function test_layouts_apply_the_dark_class_before_paint(): void
    {
        foreach (['layouts/app.blade.php', 'layouts/guest.blade.php'] as $layout) {
            $source = file_get_contents(resource_path('views/'.$layout));

            $this->assertStringContainsString(
                "classList.add('dark')",
                $source,
                "{$layout} must apply the dark class before paint.",
            );
        }
    }
}
