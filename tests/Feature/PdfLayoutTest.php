<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\SalesQuote;
use App\Models\Setting;
use App\Support\DocumentData;
use App\Support\PdfLayout;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\SeedsDatabase;
use Tests\Support\PdfText;
use Tests\TestCase;

/**
 * `PdfLayout` — the missing link between Settings -> Templates and the renderer.
 *
 * `document_templates` and a full CRUD shipped in August 2026 and nothing read them:
 * the renderer hard-coded one design, so a customer could build four templates, set
 * one as default, and every invoice came out identical. The settings screen was a
 * convincing lie.
 *
 * These tests are the ones that would have caught the four separate ways it stayed
 * a lie. Every one of them was live until this pass, and none was visible to the
 * suite:
 *
 *   1. `style` was not in `DocumentTemplate::$fillable`, so mass assignment silently
 *      discarded the design the operator picked.
 *   2. The constructor validated the style with `in_array($style, STYLES)` -- against
 *      the array's LABELS -- so every style fell back to `rail` and all five produced
 *      byte-identical PDFs.
 *   3. `normaliseBlocks()` re-added every block the operator had switched off, so
 *      "hidden" did nothing.
 *   4. The renderer printed a fixed sequence and consulted `has()` only to skip a
 *      piece, so "reordered" did nothing either.
 *
 * A test that asserts a class has a method is worth nothing. These drive the real
 * renderer and read the rendered bytes back.
 */
class PdfLayoutTest extends TestCase
{
    use SeedsDatabase;

    /**
     * A template of $type, made the default, printing in $style.
     */
    private function template(string $type, string $style = PdfLayout::STYLE_RAIL, array $blocks = []): DocumentTemplate
    {
        DocumentTemplate::where('type', $type)->update(['is_default' => false]);

        return DocumentTemplate::create([
            'name' => 'Test '.$type.' '.$style,
            'type' => $type,
            'style' => $style,
            'layout' => [
                'style' => $style,
                'blocks' => $blocks === [] ? PdfLayout::DEFAULT_BLOCK_ORDER : $blocks,
            ],
            'is_default' => true,
        ]);
    }

    /**
     * One quote for the whole test.
     *
     * Memoised rather than built per call because several of these tests render the
     * document five times and `sales_quotes.number` is unique -- and because the
     * README's own maintainer note warns that a `setUp()` override silently disables
     * the seeding trait, which is why there is no setUp() here at all.
     */
    private ?SalesQuote $quote = null;

    private function quote(): SalesQuote
    {
        if ($this->quote !== null) {
            return $this->quote;
        }

        $quote = SalesQuote::create([
            'number' => 'QUO-LAYOUT-0001',
            'customer_id' => $this->customer('Layout Customer Ltd')->id,
            'issue_date' => '2026-10-06',
            'expiry_date' => '2026-11-05',
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '300.00',
            'tax_amount' => '51.00',
            'total' => '351.00',
            'notes' => 'Layout test document.',
        ]);

        foreach ([['First line', 1, 100.00], ['Second line', 2, 100.00]] as [$description, $qty, $price]) {
            $quote->items()->create([
                'product_id' => null,
                'description' => $description,
                'qty' => $qty,
                'unit_price' => $price,
                'discount_percent' => 0,
                'tax_percent' => 17,
                'line_total' => (string) ($price * $qty),
            ]);
        }

        return $this->quote = $quote->fresh();
    }

    /**
     * The HTML the renderer produces for a quote.
     */
    private function html(): string
    {
        return view('documents.pdf', DocumentData::build($this->quote()))->render();
    }

    /**
     * The PDF bytes the renderer produces for a quote.
     */
    private function pdf(): string
    {
        return Pdf::loadHTML($this->html())
            ->setPaper('a4', 'portrait')
            ->setOptions(pdf_options())
            ->output();
    }

    // ---------------------------------------------------------------------
    // Style resolution
    // ---------------------------------------------------------------------

    /**
     * The regression that mattered most: `in_array()` against a key => label map.
     *
     * `STYLES` is `['rail' => 'Rail', ...]`, so validating with `in_array($style,
     * STYLES)` compared a key against the labels and never matched. Everything fell
     * back to `rail`, and all five designs produced byte-identical PDFs -- which is
     * why the style system looked plausible and was not.
     */
    public function test_a_style_chosen_on_the_settings_screen_is_actually_persisted(): void
    {
        $template = $this->template('quote', PdfLayout::STYLE_BANDED);

        $this->assertSame(
            PdfLayout::STYLE_BANDED,
            $template->fresh()->style,
            'style was not mass-assigned; it is missing from DocumentTemplate::$fillable',
        );

        $this->assertSame(
            PdfLayout::STYLE_BANDED,
            PdfLayout::forDocumentType('quote')->style(),
            'the persisted style did not reach the layout',
        );
    }

    #[DataProvider('styles')]
    public function test_every_style_resolves_to_itself(string $style): void
    {
        $this->assertSame($style, (new PdfLayout($style))->style());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function styles(): array
    {
        return [
            'rail' => [PdfLayout::STYLE_RAIL],
            'classic' => [PdfLayout::STYLE_CLASSIC],
            'modern' => [PdfLayout::STYLE_MODERN],
            'minimal' => [PdfLayout::STYLE_MINIMAL],
            'banded' => [PdfLayout::STYLE_BANDED],
        ];
    }

    /**
     * A typo in a setting must not stop a production order completing, so an
     * unknown style falls back rather than throwing.
     */
    public function test_an_unknown_style_falls_back_to_the_rail_rather_than_throwing(): void
    {
        $this->assertSame(PdfLayout::STYLE_RAIL, (new PdfLayout('art-deco'))->style());
        $this->assertSame(PdfLayout::STYLE_RAIL, (new PdfLayout(''))->style());
    }

    public function test_a_document_type_with_no_template_still_prints(): void
    {
        $layout = PdfLayout::forDocumentType('credit_note');

        $this->assertSame(PdfLayout::STYLE_RAIL, $layout->style());
        $this->assertSame(PdfLayout::DEFAULT_BLOCK_ORDER, $layout->blocks());
    }

    /**
     * Only the DEFAULT template for a type is read. Two rows, one default.
     */
    public function test_only_the_default_template_is_used(): void
    {
        $this->template('quote', PdfLayout::STYLE_CLASSIC);

        DocumentTemplate::create([
            'name' => 'Second quote template',
            'type' => 'quote',
            'style' => PdfLayout::STYLE_MINIMAL,
            'layout' => ['style' => PdfLayout::STYLE_MINIMAL, 'blocks' => PdfLayout::DEFAULT_BLOCK_ORDER],
            'is_default' => false,
        ]);

        $this->assertSame(PdfLayout::STYLE_CLASSIC, PdfLayout::forDocumentType('quote')->style());
    }

    /**
     * Three document types have no template row of their own -- the enum has eight
     * values and nine types print. They alias onto the type they resemble rather
     * than sitting silently on a default nobody chose.
     */
    #[DataProvider('aliases')]
    public function test_a_type_with_no_row_of_its_own_aliases_onto_one_that_has(string $documentType, string $aliasType): void
    {
        $this->template($aliasType, PdfLayout::STYLE_BANDED);

        $this->assertSame(
            PdfLayout::STYLE_BANDED,
            PdfLayout::forDocumentType($documentType)->style(),
            $documentType.' should print with the '.$aliasType.' template',
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function aliases(): array
    {
        return [
            'recurring invoice' => ['recurring_invoice', 'invoice'],
            'purchase quote' => ['purchase_quote', 'purchase_order'],
            'debit note' => ['debit_note', 'purchase_invoice'],
        ];
    }

    // ---------------------------------------------------------------------
    // Block defences
    // ---------------------------------------------------------------------

    /**
     * A stale or hand-edited row must not be able to inject an arbitrary block name
     * into the renderer.
     */
    public function test_unknown_block_keys_are_dropped(): void
    {
        $layout = new PdfLayout(PdfLayout::STYLE_RAIL, [
            'party', 'items', '<script>alert(1)</script>', '../../etc/passwd', 123, null,
        ]);

        foreach ($layout->blocks() as $block) {
            $this->assertArrayHasKey($block, PdfLayout::BLOCKS, 'an unrecognised block reached the renderer');
        }

        $this->assertTrue($layout->has('party'));
        $this->assertTrue($layout->has('items'));
    }

    /**
     * Every block is the operator's to switch off -- including the letterhead and
     * the line items, which used to be forced. A crafted POST dropping them must
     * not drag them back: the settings screen must mean what it says.
     */
    public function test_every_block_is_removable_and_nothing_is_restored_behind_the_operators_back(): void
    {
        foreach (PdfLayout::BLOCKS as $block => $definition) {
            $this->assertTrue(
                $definition['removable'],
                $block.' cannot be switched off, but the settings screen promises it can',
            );
        }

        $layout = new PdfLayout(PdfLayout::STYLE_RAIL, ['totals', 'footer']);

        $this->assertFalse($layout->has('items'), 'the line items table was restored behind the operator');
        $this->assertFalse($layout->has('header'), 'the letterhead was restored behind the operator');
        $this->assertFalse($layout->has('title'), 'the title was restored behind the operator');
    }

    /**
     * The one ordering rule that survives: WHEN the letterhead prints, it prints
     * first. An operator who switched it off gets a document without it, and no
     * ordering rule reorders what is not there.
     */
    public function test_the_letterhead_prints_first_wherever_the_template_put_it(): void
    {
        foreach ([
            ['items', 'totals', 'header', 'footer'],
            ['header', 'footer'],
            ['party', 'header'],
        ] as $stored) {
            $layout = new PdfLayout(PdfLayout::STYLE_RAIL, $stored);

            $this->assertSame('header', $layout->blocks()[0], 'the letterhead did not print first');
        }
    }

    public function test_a_switched_off_letterhead_stays_off(): void
    {
        $layout = new PdfLayout(PdfLayout::STYLE_RAIL, ['items', 'totals']);

        $this->assertFalse($layout->has('header'), 'the switched-off letterhead came back');
    }

    /**
     * `test_a_switched_off_letterhead_stays_off` covers the omission side; this
     * is its mirror for the default order, which must keep carrying the
     * letterhead so a freshly seeded (or never-saved) template prints one.
     */
    public function test_a_template_that_expresses_no_preference_still_prints_every_block(): void
    {
        $layout = new PdfLayout(PdfLayout::STYLE_RAIL, []);

        $this->assertSame(PdfLayout::DEFAULT_BLOCK_ORDER, $layout->blocks());
    }

    /**
     * THE REGRESSION: unhiding a section was impossible.
     *
     * `normaliseBlocks()` re-added every optional block the template did not list,
     * so unticking "Bank details" on the settings form left the bank details on the
     * document. The screen said "Tick to show"; the only thing the tick did was
     * nothing.
     */
    public function test_a_section_the_operator_switched_off_stays_off_the_document(): void
    {
        $layout = new PdfLayout(PdfLayout::STYLE_RAIL, [
            'header', 'party', 'items', 'totals', 'signature', 'footer',
        ]);

        $this->assertFalse($layout->has('bank'), 'the bank block came back on its own');
        $this->assertFalse($layout->has('terms'), 'the payment terms came back on their own');
        $this->assertFalse($layout->has('notes'), 'the notes block came back on their own');

        $this->assertTrue($layout->has('party'));
        $this->assertTrue($layout->has('totals'));

        // Registry order, not print order: the list is what the settings screen
        // offers to switch back on, so it reads the same every time.
        $this->assertSame(['title', 'meta', 'bank', 'terms', 'notes'], $layout->hiddenBlocks());
    }

    /**
     * The new `title` block -- the "INVOICE" heading and the document number --
     * prints by default from every style's own placement, and is gone from the
     * printed page the moment the template does not list it.
     *
     * Read from the PDF text, not the layout object: the block is consulted by
     * four different placements (classic box, masthead band, minimal topline,
     * rail canvas), and only the printed bytes can prove ALL of them obey.
     */
    public function test_the_document_title_can_be_hidden_from_every_style(): void
    {
        $styles = [
            PdfLayout::STYLE_RAIL,
            PdfLayout::STYLE_CLASSIC,
            PdfLayout::STYLE_MODERN,
            PdfLayout::STYLE_MINIMAL,
            PdfLayout::STYLE_BANDED,
        ];

        foreach ($styles as $style) {
            $this->template('quote', $style, array_values(array_diff(
                PdfLayout::DEFAULT_BLOCK_ORDER,
                ['title'],
            )));

            $text = PdfText::all($this->pdf());

            $this->assertStringNotContainsString(
                'QUO-LAYOUT-0001',
                $text,
                $style.' printed the document number despite the title block being off',
            );
        }
    }

    public function test_the_document_title_prints_untouched_for_every_style_by_default(): void
    {
        foreach ([PdfLayout::STYLE_RAIL, PdfLayout::STYLE_CLASSIC, PdfLayout::STYLE_MODERN, PdfLayout::STYLE_MINIMAL, PdfLayout::STYLE_BANDED] as $style) {
            $this->template('quote', $style);

            $text = PdfText::all($this->pdf());

            $this->assertStringContainsString('QUO-LAYOUT-0001', $text, $style.' lost the document number');
        }
    }

    /**
     * Distinct from an empty list: nothing stored at all means the full default
     * order, whereas a list that has been filtered must not be topped up behind the
     * operator's back.
     */
    public function test_a_template_that_stores_no_list_gets_the_default_order(): void
    {
        $this->assertSame(PdfLayout::DEFAULT_BLOCK_ORDER, (new PdfLayout)->blocks());
    }

    public function test_duplicates_in_a_stored_list_collapse(): void
    {
        $layout = new PdfLayout(PdfLayout::STYLE_RAIL, ['items', 'totals', 'items', 'totals']);

        $this->assertSame(array_values(array_unique($layout->blocks())), $layout->blocks());
        $this->assertSame(1, count(array_keys($layout->blocks(), 'items', true)));
    }

    // ---------------------------------------------------------------------
    // Order and placement
    // ---------------------------------------------------------------------

    /**
     * THE REGRESSION: reordering did nothing.
     *
     * The renderer printed a fixed sequence and used `has()` only to decide whether
     * to skip a piece, so a template that moved the totals above the line items
     * produced an identical document. The arrows in the settings screen were
     * decoration.
     *
     * Read off the printed HTML rather than off `canvasBlocks()`, because the method
     * returning the right order and the renderer walking it are two separate claims
     * and only one of them is the feature.
     */
    public function test_the_order_the_template_chose_is_the_order_the_renderer_prints(): void
    {
        $this->template('quote', PdfLayout::STYLE_CLASSIC, [
            'header', 'items', 'totals', 'party', 'meta', 'footer',
        ]);

        $html = $this->html();

        $needles = [
            'items' => '<table class="items">',
            'party' => 'Bill to',
            'meta' => 'Issue date',
            'totals' => '<table class="totals">',
            'footer' => '<table class="footer">',
        ];

        $positions = [];

        foreach ($needles as $block => $needle) {
            $offset = mb_strpos($html, $needle);

            // Strictly `false`, not `-1`: `mb_strpos` returns false on a miss and
            // casting it to int gives 0, which reads as "printed at the very top"
            // and sorts a block that never printed to the front of the document.
            $this->assertNotFalse($offset, $block.' never printed; looked for "'.$needle.'"');

            $positions[$block] = $offset;
        }

        $sorted = $positions;
        asort($sorted);

        $this->assertSame(
            ['items', 'totals', 'party', 'meta', 'footer'],
            array_keys($sorted),
            'the document printed in its own order, not the template order',
        );
    }

    /**
     * In the rail design, bill-to, bank details and payment terms travel down the
     * coloured band rather than through the flow -- so they are excluded from the
     * canvas and rendered by `railBlocks()` instead, in the same order.
     */
    public function test_the_rail_design_takes_its_three_blocks_out_of_the_canvas(): void
    {
        $layout = new PdfLayout(PdfLayout::STYLE_RAIL, PdfLayout::DEFAULT_BLOCK_ORDER);

        $this->assertSame(['party', 'bank', 'terms'], $layout->railBlocks());

        foreach ($layout->railBlocks() as $block) {
            $this->assertNotContains($block, $layout->canvasBlocks(), $block.' printed twice');
        }
    }

    /**
     * Every other style prints them in the canvas.
     */
    public function test_only_the_rail_moves_its_blocks_out_of_the_canvas(): void
    {
        foreach ([PdfLayout::STYLE_CLASSIC, PdfLayout::STYLE_MODERN, PdfLayout::STYLE_MINIMAL, PdfLayout::STYLE_BANDED] as $style) {
            $layout = new PdfLayout($style, PdfLayout::DEFAULT_BLOCK_ORDER);

            $this->assertSame([], $layout->railBlocks(), $style.' should not use the rail');
            $this->assertContains('party', $layout->canvasBlocks());
            $this->assertContains('bank', $layout->canvasBlocks());
        }
    }

    public function test_a_block_hidden_in_the_rail_design_is_hidden_everywhere(): void
    {
        $layout = new PdfLayout(PdfLayout::STYLE_RAIL, ['header', 'party', 'items', 'totals', 'footer']);

        $this->assertSame(['party'], $layout->railBlocks());
        $this->assertNotContains('bank', $layout->canvasBlocks());
        $this->assertNotContains('bank', $layout->railBlocks());
    }

    // ---------------------------------------------------------------------
    // The style reaches the printed document
    // ---------------------------------------------------------------------

    /**
     * "Changing the template changes the output" has to be pinned rather than
     * assumed -- it is the whole claim the subsystem makes.
     *
     * The frame element is asserted per style, because each is a different element
     * in the markup: the rail design's band, the masthead the other three share, the
     * minimal topline, and the classic header box that only that design draws.
     */
    public function test_the_style_selects_the_frame_that_is_printed(): void
    {
        $expected = [
            PdfLayout::STYLE_RAIL => '<div class="rail">',
            PdfLayout::STYLE_CLASSIC => '<div class="classic-box">',
            PdfLayout::STYLE_MODERN => '<div class="masthead"',
            PdfLayout::STYLE_MINIMAL => '<div class="topline">',
            PdfLayout::STYLE_BANDED => '<div class="masthead"',
        ];

        foreach ($expected as $style => $frame) {
            $this->template('quote', $style);

            $html = $this->html();

            $this->assertStringContainsString(
                '<body class="style-'.$style.'">',
                $html,
                $style.' did not reach the body class',
            );

            $this->assertStringContainsString($frame, $html, $style.' did not print its own frame');
        }
    }

    /**
     * Two styles, one document, two different documents.
     *
     * Byte-for-byte equality is the assertion that failed before this pass: all
     * five produced the same file, which is why nothing had ever looked at them.
     */
    public function test_two_styles_print_two_different_documents(): void
    {
        $rendered = [];

        foreach ([PdfLayout::STYLE_RAIL, PdfLayout::STYLE_CLASSIC, PdfLayout::STYLE_MODERN, PdfLayout::STYLE_MINIMAL, PdfLayout::STYLE_BANDED] as $style) {
            $this->template('quote', $style);
            $rendered[$style] = $this->pdf();
        }

        $distinct = array_unique($rendered);

        $this->assertCount(
            count($rendered),
            $distinct,
            'two or more styles rendered an identical document: '.implode(', ', array_keys($rendered)),
        );

        foreach ($rendered as $style => $pdf) {
            $this->assertStringStartsWith('%PDF', $pdf, $style.' did not render a PDF');
        }
    }

    /**
     * A style that produces a broken page is worse than one that looks plain.
     */
    public function test_every_style_prints_the_document_body(): void
    {
        foreach ([PdfLayout::STYLE_RAIL, PdfLayout::STYLE_CLASSIC, PdfLayout::STYLE_MODERN, PdfLayout::STYLE_MINIMAL, PdfLayout::STYLE_BANDED] as $style) {
            $this->template('quote', $style);

            $text = PdfText::all($this->pdf());

            $this->assertStringContainsString('QUO-LAYOUT-0001', $text, $style.' lost the document number');
            $this->assertStringContainsString('First line', $text, $style.' lost a line item');
            $this->assertStringContainsString('Page 1', $text, $style.' lost the page number');
        }
    }

    /**
     * The block the operator switched off must be absent from the printed page, not
     * merely from the stored array.
     *
     * Read from the PDF rather than the HTML because the block labels are uppercased
     * by CSS, and reading the HTML would assert against the template rather than
     * against the document the customer holds.
     */
    public function test_a_hidden_block_is_absent_from_the_printed_document(): void
    {
        $this->template('quote', PdfLayout::STYLE_CLASSIC, [
            'header', 'party', 'meta', 'items', 'totals', 'footer',
        ]);

        $text = PdfText::all($this->pdf());

        $this->assertStringContainsString('BILL TO', $text);
        $this->assertStringNotContainsString('NOTES', $text, 'the notes block printed despite being switched off');
        $this->assertStringNotContainsString('Authorised signature', $text);
    }

    /**
     * The rail prints bank details down the band, so they are not missing from the
     * document -- they have moved. Both are checked together because getting it
     * wrong in either direction is invisible.
     */
    public function test_a_block_prints_once_wherever_the_style_puts_it(): void
    {
        Setting::set('company.bank_account_number', '1234-5678');
        Setting::set('company.payment_terms', 'Net 30 days from the invoice date.');
        Setting::flushCache();

        $this->template('quote', PdfLayout::STYLE_RAIL);
        $rail = PdfText::all($this->pdf());

        $this->assertSame(
            1,
            substr_count($rail, '1234-5678'),
            'the rail design did not print the bank details exactly once',
        );

        $this->template('quote', PdfLayout::STYLE_CLASSIC);
        $classic = PdfText::all($this->pdf());

        $this->assertSame(1, substr_count($classic, '1234-5678'), 'the classic design did not print the bank details exactly once');
        $this->assertStringContainsString('Net 30 days', $classic);
    }

    /**
     * A company with no bank account on file gets no bank block -- not a heading
     * over nothing, which reads as a document fault rather than as missing data.
     */
    public function test_a_block_with_nothing_to_say_prints_no_heading(): void
    {
        Setting::set('company.bank_account_number', '');
        Setting::set('company.payment_terms', '');
        Setting::flushCache();

        $this->template('quote', PdfLayout::STYLE_RAIL);

        $text = PdfText::all($this->pdf());

        $this->assertStringNotContainsString('Bank', $text);
        $this->assertStringNotContainsString('Terms', $text);
    }

    // ---------------------------------------------------------------------
    // The utility print suite follows the invoice design
    // ---------------------------------------------------------------------

    /**
     * `?format=pdf` list exports are not customer documents, so they have no
     * template of their own -- but an office printing the suite expects the
     * invoice's colour and the operator's stylesheet to reach these sheets
     * as well, so the one stylesheet styles everything.
     *
     * The view is rendered with the exact keys `ExportsCsv::renderPdf()` passes;
     * the invoice default here carries a marker colour the branding setting
     * cannot produce.
     */
    public function test_list_export_pdfs_take_the_invoice_templates_colour_and_css(): void
    {
        $previous = DocumentTemplate::query()
            ->where('type', 'invoice')
            ->where('is_default', true)
            ->first();

        DocumentTemplate::query()
            ->where('type', 'invoice')
            ->update(['is_default' => false]);

        DocumentTemplate::create([
            'name' => 'Operator suite '.uniqid(),
            'type' => 'invoice',
            'style' => 'rail',
            'colors' => ['primary' => '#3b0a45', 'accent' => '#654321', 'text' => '#111111'],
            'css' => '.export-op-glyphs { letter-spacing: 0.4em; }',
            'is_default' => true,
        ]);

        try {
            $html = View::make('exports.pdf', [
                'title' => 'Expense claims',
                'headers' => ['Number', 'Amount'],
                'rows' => [['EC-0001', '100.00']],
                'brandColor' => PdfLayout::forType('invoice')->color('primary', settings('branding.primary_color', '#4f46e5')),
                'customCss' => PdfLayout::forType('invoice')->templateCss(),
            ])->render();

            $this->assertStringContainsString(
                '#3b0a45',
                $html,
                'the invoice template colour must reach the list export',
            );

            $this->assertStringContainsString(
                '.export-op-glyphs',
                $html,
                'the operator stylesheet must style the list export too',
            );
        } finally {
            DocumentTemplate::query()->where('name', 'like', 'Operator suite %')->delete();

            $previous?->update(['is_default' => true]);
        }
    }

    public function test_a_list_export_without_a_custom_stylesheet_prints_none(): void
    {
        $previous = DocumentTemplate::query()
            ->where('type', 'invoice')
            ->where('is_default', true)
            ->first();

        DocumentTemplate::query()
            ->where('type', 'invoice')
            ->update(['is_default' => false]);

        DocumentTemplate::create([
            'name' => 'Operator quiet '.uniqid(),
            'type' => 'invoice',
            'style' => 'rail',
            'colors' => ['primary' => '#3b0a45', 'accent' => '#654321', 'text' => '#111111'],
            'css' => null,
            'is_default' => true,
        ]);

        try {
            $html = View::make('exports.pdf', [
                'title' => 'Expense claims',
                'headers' => ['Number'],
                'rows' => [['EC-0001']],
                'brandColor' => PdfLayout::forType('invoice')->color('primary', settings('branding.primary_color', '#4f46e5')),
                'customCss' => PdfLayout::forType('invoice')->templateCss(),
            ])->render();

            $this->assertStringNotContainsString(
                'op-glyphs',
                $html,
                'no operator stylesheet was set, so none may appear',
            );
        } finally {
            DocumentTemplate::query()->where('name', 'like', 'Operator quiet %')->delete();

            $previous?->update(['is_default' => true]);
        }
    }
}
