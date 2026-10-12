<?php

namespace Tests\Feature\Views;

use Tests\TestCase;

/**
 * WCAG contrast guard for the colour tokens.
 *
 * Dark mode had been reviewed by reading the code and never measured. Measuring it
 * found two failures that reading it had missed:
 *
 *   text-primary   #4f46e5 on the dark surface #11141f  ->  2.92:1
 *   text-ink-faint #94a3b8 on white                     ->  2.56:1
 *
 * The first hit roughly 150 usages and the second roughly 500, so "dark mode looks
 * fine" was carrying a lot of weight for two numbers nobody had checked.
 *
 * These are arithmetic assertions on the token values, not screenshots: the palette
 * cannot drift back below the threshold without failing the build. The thresholds
 * are the WCAG 2.1 AA figures -- 4.5:1 for body text, 3:1 for large text and for
 * non-text indicators such as focus rings and active borders.
 */
class ContrastTest extends TestCase
{
    private const AA_BODY = 4.5;

    private const AA_LARGE = 3.0;

    /**
     * The palette as shipped, mirroring resources/css/app.css.
     *
     * Read from the file at test time rather than duplicated here, so editing the
     * CSS without meeting the threshold fails the build instead of quietly
     * invalidating the numbers in this test.
     */
    private function tokens(): array
    {
        $css = file_get_contents(base_path('resources/css/app.css'));

        preg_match('/:root\s*\{(.*?)\}/s', $css, $root);
        preg_match('/\.dark\s*\{(.*?)\}/s', $css, $dark);

        $parse = static function (string $block): array {
            preg_match_all('/--color-([a-z-]+):\s*([\d\s]+);/', $block, $m, PREG_SET_ORDER);
            $out = [];
            foreach ($m as $set) {
                $out[$set[1]] = trim($set[2]);
            }

            return $out;
        };

        return ['light' => $parse($root[1] ?? ''), 'dark' => $parse($dark[1] ?? '')];
    }

    private static function toRgb(string $triplet): array
    {
        $parts = array_map('trim', explode(' ', $triplet));

        return [(int) $parts[0], (int) $parts[1], (int) $parts[2]];
    }

    private static function luminance(string $triplet): float
    {
        [$r, $g, $b] = array_map(
            fn (int $c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4,
            self::toRgb($triplet),
        );

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    private static function contrast(string $a, string $b): float
    {
        $l1 = self::luminance($a);
        $l2 = self::luminance($b);

        if ($l1 < $l2) {
            [$l1, $l2] = [$l2, $l1];
        }

        return ($l1 + 0.05) / ($l2 + 0.05);
    }

    /**
     * Every text-on-surface combination in both modes.
     */
    public function test_text_pairs_meet_wcag_aa(): void
    {
        $tokens = $this->tokens();
        $checked = 0;

        foreach (['light', 'dark'] as $mode) {
            foreach (['canvas', 'surface', 'surface-muted'] as $background) {
                foreach (['ink', 'ink-soft', 'ink-faint'] as $foreground) {
                    $ratio = self::contrast($tokens[$mode][$foreground], $tokens[$mode][$background]);
                    $checked++;

                    $this->assertGreaterThanOrEqual(
                        self::AA_BODY,
                        $ratio,
                        sprintf(
                            '%s mode: %s on %s is %.2f:1, below the %.1f:1 needed for body text.',
                            $mode, $foreground, $background, $ratio, self::AA_BODY,
                        ),
                    );
                }
            }
        }

        $this->assertSame(18, $checked, 'expected 3 foregrounds x 3 backgrounds x 2 modes');
    }

    /**
     * Brand-coloured text is the failure that was hardest to spot by eye, because
     * `primary` looks correct everywhere it is a *fill* and wrong everywhere it is
     * *text* on a dark surface. Both are asserted, so the fill cannot be lightened
     * to fix the text and break white button labels.
     */
    public function test_brand_text_is_legible_without_breaking_white_button_labels(): void
    {
        $tokens = $this->tokens();

        // Text on the dark surface.
        $ink = self::contrast($tokens['dark']['primary-ink'], $tokens['dark']['surface']);
        $this->assertGreaterThanOrEqual(
            self::AA_BODY,
            $ink,
            sprintf('dark: primary-ink on surface is %.2f:1.', $ink),
        );

        // The fill is unchanged, so white text on buttons still reads.
        $label = self::contrast('255 255 255', $tokens['light']['primary']);
        $this->assertGreaterThanOrEqual(
            self::AA_BODY,
            $label,
            sprintf('light: white on primary is %.2f:1.', $label),
        );

        $strong = self::contrast('255 255 255', $tokens['light']['primary-strong']);
        $this->assertGreaterThanOrEqual(self::AA_BODY, $strong, sprintf('white on primary-strong is %.2f:1.', $strong));
    }

    /**
     * A focus ring is a non-text indicator, so 3:1 is the bar -- but the old
     * hardcoded `focus:border-indigo-700` measured 2.32:1 against the dark surface
     * and was effectively invisible on keyboard navigation.
     */
    public function test_the_focus_indicator_is_visible_in_dark_mode(): void
    {
        $tokens = $this->tokens();

        $ratio = self::contrast($tokens['dark']['primary-ink'], $tokens['dark']['surface']);

        $this->assertGreaterThanOrEqual(
            self::AA_LARGE,
            $ratio,
            sprintf('dark: focus indicator is %.2f:1 against the surface.', $ratio),
        );

        $this->assertStringNotContainsString(
            'focus:border-indigo-',
            file_get_contents(base_path('resources/views/components/nav-link.blade.php')),
            'nav-link must not hardcode an indigo focus ring; it will not follow the tenant brand.',
        );
    }

    /**
     * The ink hierarchy has to stay ordered, or "faint" text ends up darker than
     * "soft" text and the visual rhythm inverts.
     */
    public function test_the_ink_hierarchy_stays_ordered(): void
    {
        foreach (['light', 'dark'] as $mode) {
            $tokens = $this->tokens()[$mode];

            $ink = self::contrast($tokens['ink'], $tokens['surface']);
            $soft = self::contrast($tokens['ink-soft'], $tokens['surface']);
            $faint = self::contrast($tokens['ink-faint'], $tokens['surface']);

            $this->assertGreaterThan($soft, $ink, "{$mode}: ink must be the strongest");
            $this->assertGreaterThan($faint, $soft, "{$mode}: ink-soft must be stronger than ink-faint");
        }
    }

    /**
     * Every layout that paints a page must apply the persisted theme before first
     * paint, and must honour the tenant's configured default rather than jumping
     * from the OS preference on login.
     */
    public function test_every_layout_resolves_the_theme_identically(): void
    {
        $layouts = [
            'layouts/app.blade.php',
            'layouts/guest.blade.php',
            'employees/attendance/qr-result.blade.php',
        ];

        foreach ($layouts as $layout) {
            $source = file_get_contents(base_path('resources/views/'.$layout));

            $this->assertStringContainsString(
                "classList.add('dark')",
                $source,
                "{$layout} must apply the theme before paint",
            );
            $this->assertStringContainsString(
                "localStorage.getItem('cb-theme')",
                $source,
                "{$layout} must read the stored preference",
            );
            $this->assertStringContainsString(
                "serverDefault",
                $source,
                "{$layout} must honour the tenant's dark_mode setting, not just the OS",
            );
            $this->assertStringContainsString(
                '@vite',
                $source,
                "{$layout} must use the compiled stylesheet, not the Tailwind CDN",
            );
            $this->assertStringNotContainsString(
                'cdn.tailwindcss.com',
                $source,
                "{$layout} must not load the Tailwind CDN; design-system tokens do not exist there",
            );
            $this->assertStringContainsString(
                '--color-primary-ink',
                $source,
                "{$layout} must supply the brand ink token for both modes",
            );
        }
    }
}
