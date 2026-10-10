<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The double-submit guard (README UI-5).
 *
 * A double click on Save, or Enter pressed twice on a slow connection, posts the
 * same document twice — two invoices with two numbers, two receipts against one
 * invoice. The damage surfaces at month end, by which point it is expensive.
 *
 * The guard is one capture-phase `submit` listener in `resources/js/app.js` rather
 * than an `x-on:submit` on each form: there are hundreds of forms here, and a
 * per-form handler is exactly what gets forgotten on the four hundredth screen.
 *
 * What this test does and does not prove:
 *
 *  - It DOES pin that the listener is present, that it disables submit buttons,
 *    and that it releases them again -- so the guard cannot be silently deleted.
 *  - It does NOT prove the browser behaviour. There is no JS test runner in this
 *    project, so nothing here executes the listener. Adding Vitest and jsdom for
 *    one handler is not worth the dependency; if a JS runner is ever added, this
 *    is the test to replace with a real one.
 */
class DoubleSubmitGuardTest extends TestCase
{
    private function guardSource(): string
    {
        $path = resource_path('js/app.js');

        $this->assertFileExists($path);

        $source = file_get_contents($path);

        $this->assertIsString($source);

        return $source;
    }

    public function test_a_global_submit_guard_is_registered(): void
    {
        $this->assertStringContainsString(
            "document.addEventListener('submit'",
            $this->guardSource(),
            'the global submit listener is gone; forms can be submitted twice again',
        );
    }

    /**
     * Capture phase, so the guard runs before any per-form handler can navigate.
     */
    public function test_the_guard_runs_in_the_capture_phase(): void
    {
        $this->assertMatchesRegularExpression(
            "/addEventListener\('submit'.*true\);/s",
            $this->guardSource(),
            'the listener must be registered with capture=true',
        );
    }

    public function test_the_guard_blocks_a_repeat_submission(): void
    {
        $source = $this->guardSource();

        $this->assertStringContainsString('preventDefault', $source);
        $this->assertStringContainsString('stopImmediatePropagation', $source);
        $this->assertStringContainsString("form.dataset.submitting === '1'", $source);
    }

    /**
     * A form that never navigates would otherwise stay locked for ever.
     */
    public function test_the_guard_releases_the_buttons(): void
    {
        $source = $this->guardSource();

        $this->assertStringContainsString('button.disabled = false', $source);
        $this->assertStringContainsString('removeAttribute', $source);
        $this->assertMatchesRegularExpression('/setTimeout\(/', $source);
    }

    /**
     * The opt-out exists for forms that legitimately need to submit twice, but it
     * must not be used casually: `event` fires for every form on the page, so a
     * stray opt-out is as likely to be a typo as a decision.
     */
    public function test_no_form_opts_out_of_the_guard(): void
    {
        $optedOut = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views')),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            if (str_contains((string) file_get_contents($file->getPathname()), 'submitting-guard="off"')) {
                $optedOut[] = str_replace(
                    [resource_path('views').DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR],
                    ['', '/'],
                    $file->getPathname(),
                );
            }
        }

        $this->assertSame(
            [],
            $optedOut,
            "forms opt out of the double-submit guard:\n".implode("\n", $optedOut),
        );
    }
}
