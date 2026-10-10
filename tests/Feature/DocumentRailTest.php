<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The document rail (README LOW-2).
 *
 * The rail used to be a fixed-height `<td>` beside the canvas. DomPDF cannot
 * repeat a table row across a page break, so it rendered on page 1 only and page
 * 2 of a long document came out with no brand, no company name and no bill-to.
 *
 * It is now a `position: fixed` block outside the flow, which DomPDF does
 * re-emit per page, and the canvas is inset with a margin to clear it.
 *
 * **What this test does and does not prove.** It pins the *structure* — the rail
 * must not go back into a table, and the canvas must be inset clear of it. It
 * cannot prove the rendering: that needs a rasterised page, and the test suite
 * runs without a PDF renderer or image library. `preview_document_pdfs.php` plus
 * `render_invoice_pdf.py` is the check for that, and a 48-line quote is what makes
 * it meaningful.
 */
class DocumentRailTest extends TestCase
{
    private function template(): string
    {
        return (string) file_get_contents(resource_path('views/documents/pdf.blade.php'));
    }

    /**
     * The regression itself: a fixed-position rail, not a table cell.
     */
    public function test_the_rail_is_fixed_rather_than_a_table_cell(): void
    {
        $source = $this->template();

        $this->assertMatchesRegularExpression(
            '/\.rail\s*\{[^}]*position:\s*fixed/s',
            $source,
            'the rail must be position:fixed or it will only render on page 1 again',
        );
    }

    public function test_the_rail_is_not_inside_the_sheet_table(): void
    {
        $source = $this->template();

        $this->assertStringNotContainsString(
            '<td class="rail"',
            $source,
            'the rail cell is back; a table cell cannot repeat across a page break',
        );

        $this->assertStringNotContainsString(
            'table.sheet',
            $source,
            'the two-cell sheet table is back',
        );
    }

    /**
     * The canvas must clear the rail, and it must be inset in normal flow --
     * a fixed-height cell is what made pagination impossible in the first place.
     */
    public function test_the_canvas_is_inset_clear_of_the_rail(): void
    {
        $source = $this->template();

        $this->assertMatchesRegularExpression(
            '/\.canvas\s*\{[^}]*margin-left/s',
            $source,
            'the canvas needs a left margin to clear the fixed rail',
        );

        $this->assertStringContainsString('<div class="canvas">', $source);
    }

    /**
     * The rail's width must exclude its padding.
     *
     * DomPDF adds padding outside the declared width and ignores `box-sizing:
     * border-box` when it does so. At `width: 28.5%` with 10mm of padding the
     * painted band reached 33.3% of the page against a 33% canvas inset, and the
     * rail covered the first character of every heading -- "QUOTE" rendered as
     * "Q U O T E" and "Date" as "ate" on every page.
     *
     * This cannot prove the geometry, but it pins the decision: the width has to
     * be the smaller number, and the comment has to keep saying why.
     */
    public function test_the_rail_width_leaves_room_for_its_padding(): void
    {
        $source = $this->template();

        $matched = preg_match('/\.rail\s*\{.*?width:\s*(\d+(?:\.\d+)?)%/s', $source, $m);

        $this->assertSame(1, $matched, 'no .rail block with a percentage width was found');

        $width = (float) $m[1];

        $this->assertLessThan(
            28.5,
            $width,
            'the rail width plus its 10mm of padding must stay under the 33% canvas inset',
        );
    }

    /**
     * The rail content has to live inside the fixed block, or it renders once
     * beside the canvas and never again.
     */
    public function test_the_rail_content_sits_inside_the_fixed_block(): void
    {
        $source = $this->template();

        $this->assertStringContainsString('<div class="rail">', $source);
        $this->assertStringContainsString('rail-thanks', $source);

        $rail = strpos($source, '<div class="rail">');
        $canvas = strpos($source, '<div class="canvas">');

        $this->assertIsInt($rail);
        $this->assertIsInt($canvas);
        $this->assertLessThan($canvas, $rail, 'the rail block must come before the canvas');
    }
}
