<?php

namespace App\Support;

use App\Models\DocumentTemplate;

/**
 * What a document should look like when it is printed.
 *
 * WHY THIS EXISTS
 *
 * `document_templates` and a full Settings -> Templates CRUD shipped a long time ago.
 * Nothing ever read them. The renderer hard-coded one design, so a customer could build
 * four templates, set one as default, and every invoice came out identical -- the
 * settings screen was a convincing lie.
 *
 * This is the missing link. It answers two questions for the renderer:
 *
 *   1. Which STYLE?    -- the visual treatment: rail, classic, modern, minimal, banded.
 *   2. Which BLOCKS?   -- the pieces of the document, in which order, which of them.
 *
 * WHY BLOCKS AND NOT PIXELS
 *
 * A free drag-and-drop canvas assumes one fixed page. Invoices paginate, and DomPDF
 * cannot repeat a table row across a page break, cannot count total pages, and cannot
 * render `@page` margin boxes at `margin: 0`. Every one of those is documented in this
 * codebase from fighting it. Give a user free positioning over that and the failure is
 * not a bug report, it is a customer reading a broken invoice.
 *
 * So the unit of arrangement is a BLOCK -- a tested, known-good piece of a document --
 * rather than a coordinate. Blocks can be shown, hidden and reordered freely. The
 * page-flow inside a document is not something a user can break, because a user cannot
 * reach it.
 */
class PdfLayout
{
    /**
     * Visual treatments. Each is a genuinely different design, not a colour swap.
     *
     *   rail     full-height coloured band down the left, repeated on every page
     *   classic  bordered header box, traditional left-aligned ledger
     *   modern   full-width brand masthead across the top
     *   minimal  no fills at all -- hairline rules, white space, ink only
     *   banded   tinted table header, zebra rows, filled total bar
     */
    public const STYLE_RAIL = 'rail';

    public const STYLE_CLASSIC = 'classic';

    public const STYLE_MODERN = 'modern';

    public const STYLE_MINIMAL = 'minimal';

    public const STYLE_BANDED = 'banded';

    public const STYLES = [
        self::STYLE_RAIL => 'Rail',
        self::STYLE_CLASSIC => 'Classic',
        self::STYLE_MODERN => 'Modern',
        self::STYLE_MINIMAL => 'Minimal',
        self::STYLE_BANDED => 'Banded',
    ];

    /**
     * What each style actually is, in the operator's terms.
     *
     * Kept beside `STYLES` rather than in the Blade because this is a property of the
     * design, not of the screen that describes it: an operator reading the template
     * name needs the same sentence wherever it is shown, and a description that only
     * exists on the create form is a description the edit form cannot show.
     */
    public const STYLE_HELP = [
        self::STYLE_RAIL => 'A full-height coloured band down the left edge, on every page. Carries the logo, company name, bill-to and bank details.',
        self::STYLE_CLASSIC => 'A bordered header box and a traditional left-aligned ledger. Prints in ink rather than brand colour.',
        self::STYLE_MODERN => 'A full-width brand masthead across the top of every page.',
        self::STYLE_MINIMAL => 'No fills at all. Hairline rules, white space and ink only - pick this when your brand colour cannot survive being flooded across a page.',
        self::STYLE_BANDED => 'Tinted table header, zebra rows and a filled total bar. The most modern of the five.',
    ];

    /**
     * The blocks a document is assembled from, in default order.
     *
     * `label` is what the settings screen shows.
     *
     * Every block is removable. The two that used to be forced -- the letterhead
     * and the line items -- are now operator decisions like the rest, because a
     * business legitimately prints documents without them: a pro-forma with no
     * totals, a delivery copy the customer's warehouse does not need a finance
     * letterhead on. The renderer still cannot break, because removability is
     * about CONTENT, not about pagination -- the page flow stays the tested one
     * whatever the operator unticks.
     *
     * `title` (the "INVOICE" heading and the document number) is consulted by
     * each style's own placement -- inside the classic box, the masthead band,
     * the minimal topline or the rail canvas -- rather than printing from its
     * own partial, which is why it is skipped by the canvas loop.
     */
    public const BLOCKS = [
        'header' => ['label' => 'Letterhead and document title', 'removable' => true],
        'title' => ['label' => 'Document kind and number', 'removable' => true],
        'party' => ['label' => 'Bill to / Supplier block', 'removable' => true],
        'meta' => ['label' => 'Dates and document details', 'removable' => true],
        'items' => ['label' => 'Line items table', 'removable' => true],
        'totals' => ['label' => 'Totals and settlement', 'removable' => true],
        'bank' => ['label' => 'Bank details', 'removable' => true],
        'terms' => ['label' => 'Payment terms', 'removable' => true],
        'notes' => ['label' => 'Notes', 'removable' => true],
        'signature' => ['label' => 'Signature block', 'removable' => true],
        'footer' => ['label' => 'Footer contact details', 'removable' => true],
    ];

    /**
     * The blocks that travel in the rail rather than the canvas.
     *
     * The rail design puts the bill-to, bank details and payment terms down the
     * coloured band instead of in the flow -- they are the three an accounts
     * department actually reads before signing. Every other block has no rail
     * equivalent and stays in the canvas.
     */
    public const RAIL_BLOCKS = ['party', 'bank', 'terms'];

    /**
     * The order used when a template expresses no preference.
     *
     * `title` sits beside the letterhead because the two are read together: the
     * company name, then what the document is and which one it is. `bank` and
     * `terms` sit before `notes` because on a real invoice they are read as
     * part of the settlement story -- where to pay, and by when -- and reading
     * them after the notes buries the only two fields the customer acts on.
     */
    public const DEFAULT_BLOCK_ORDER = [
        'header', 'title', 'party', 'meta', 'items', 'totals', 'bank', 'terms', 'notes', 'signature', 'footer',
    ];

    private string $style;

    /** @var array<int, string> */
    private array $blocks;

    private array $colors;

    private ?string $templateName;

    private ?string $headerHtml;

    private ?string $footerHtml;

    private ?string $customCss;

    public function __construct(
        string $style = self::STYLE_RAIL,
        array $blocks = [],
        array $colors = [],
        ?string $templateName = null,
        ?string $headerHtml = null,
        ?string $footerHtml = null,
        ?string $customCss = null,
    ) {
        /**
         * `array_key_exists`, not `in_array`.
         *
         * `STYLES` maps key => label -- `['rail' => 'Rail', 'modern' => 'Modern']` --
         * so `in_array($style, STYLES)` was comparing a style key against the LABELS
         * and never matched. Every template therefore fell back to `rail`, whatever
         * it asked for, and the five designs produced byte-identical PDFs. `PdfLayout`
         * is new enough that nothing had ever rendered a non-rail style to notice.
         */
        $this->style = array_key_exists($style, self::STYLES) ? $style : self::STYLE_RAIL;

        $this->blocks = $this->normaliseBlocks($blocks);

        $this->colors = array_merge([
            'primary' => settings('branding.primary_color', '#4f46e5'),
            'accent' => settings('branding.accent_color', '#0ea5e9'),
            'text' => '#1b2130',
        ], array_filter($colors));

        $this->templateName = $templateName;

        $this->headerHtml = $this->trimToNull($headerHtml);

        $this->footerHtml = $this->trimToNull($footerHtml);

        $this->customCss = $this->trimToNull($customCss);
    }

    /**
     * Document types with no template row of their own.
     *
     * `document_templates.type` is an enum of eight values, but nine document types
     * print from the shared template. Rather than widen the enum on a table that has
     * shipped, the three odd ones borrow the template of the document they most resemble.
     *
     * The alternative -- leaving them on a silent default -- means a business that
     * restyles its invoices finds its recurring invoices and purchase quotes did not
     * change, with nothing on screen to explain why.
     */
    private const TYPE_ALIASES = [
        'recurring_invoice' => 'invoice',
        'purchase_quote' => 'purchase_order',
        'debit_note' => 'purchase_invoice',
    ];

    /**
     * The layout a document of this type should print with.
     *
     * Falls back to the built-in rail design when no template is set for the type. That
     * matters: `document_templates` is empty on a deployment seeded before it existed,
     * and an ERP that cannot print a purchase order because nobody configured a
     * template is worse than one printing the default.
     */
    public static function forType(string $type): self
    {
        $template = DocumentTemplate::where('type', $type)->where('is_default', true)->first();

        if ($template === null) {
            return new self;
        }

        /**
         * The `style` COLUMN, not `layout['style']`.
         *
         * Both were written by the backfill and by the settings screen, and reading
         * the JSON while the column went unread meant the indexed, filterable,
         * reportable value could disagree with the one that decided how the document
         * printed. The column is the authority; the JSON key stays as the fallback
         * for a row written before the column existed.
         */
        $style = $template->style ?: ($template->layout['style'] ?? self::STYLE_RAIL);

        return new self(
            (string) $style,
            (array) ($template->layout['blocks'] ?? []),
            (array) ($template->colors ?? []),
            $template->name,
            (string) ($template->header_html ?? '') ?: null,
            (string) ($template->footer_html ?? '') ?: null,
            (string) ($template->css ?? '') ?: null,
        );
    }

    /**
     * Resolve from a `DocumentData::TYPES` type key, aliasing the ones with no
     * template row of their own.
     */
    public static function forDocumentType(string $documentType): self
    {
        return self::forType(self::TYPE_ALIASES[$documentType] ?? $documentType);
    }

    /**
     * Build a layout straight from a stored template, ignoring the default
     * resolution. Used by the template preview screen.
     */
    public static function forTemplate(DocumentTemplate $template): self
    {
        $style = $template->style ?: ($template->layout['style'] ?? self::STYLE_RAIL);

        return new self(
            (string) $style,
            (array) ($template->layout['blocks'] ?? []),
            (array) ($template->colors ?? []),
            $template->name,
            (string) ($template->header_html ?? '') ?: null,
            (string) ($template->footer_html ?? '') ?: null,
            (string) ($template->css ?? '') ?: null,
        );
    }

    public function style(): string
    {
        return $this->style;
    }

    public function templateName(): ?string
    {
        return $this->templateName;
    }

    /**
     * The operator's custom header HTML, or null for a template without one.
     *
     * Rendered RAW, not escaped -- it is the operator's own markup, the exact
     * field the settings screen asks them to fill, and escaping it would make
     * the form a lie again. DomPDF does not execute scripts, so the harm these
     * bytes can do inside the print is whitespace, not code.
     */
    public function headerHtml(): ?string
    {
        return $this->headerHtml;
    }

    /**
     * The operator's custom footer HTML, or null. Same raw-render rule as the header.
     */
    public function footerHtml(): ?string
    {
        return $this->footerHtml;
    }

    /**
     * The operator's custom stylesheet, or null.
     *
     * The renderer injects it AFTER its own style block, so it sits last in the
     * cascade: a rule here overrides the built-in design without the operator
     * needing `!important` to beat their own template engine.
     */
    public function templateCss(): ?string
    {
        return $this->customCss;
    }

    /**
     * A blank string is not a blank page: whitespace-only template fields are
     * dropped so the renderer never prints an empty box for them.
     */
    private function trimToNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $value;
    }

    public function color(string $key, string $fallback): string
    {
        return (string) ($this->colors[$key] ?? $fallback);
    }

    /**
     * Enabled blocks, in the order they will print.
     *
     * @return array<int, string>
     */
    public function blocks(): array
    {
        return $this->blocks;
    }

    public function has(string $block): bool
    {
        return in_array($block, $this->blocks, true);
    }

    /**
     * The blocks this template prints inside the rail, in the template's own order.
     *
     * Empty for every style but the rail. The four other designs have no coloured
     * band to print them in, and returning them regardless meant the canvas and the
     * rail both claimed the bill-to -- which is how a block ends up printed twice on
     * a document where it should appear once.
     *
     * @return array<int, string>
     */
    public function railBlocks(): array
    {
        if ($this->style !== self::STYLE_RAIL) {
            return [];
        }

        return array_values(array_intersect($this->blocks, self::RAIL_BLOCKS));
    }

    /**
     * The blocks the renderer walks through the canvas, in the template's own order.
     *
     * This is the list that makes REORDERING mean something. The template used to
     * print a fixed sequence and consult `has()` only to decide whether to skip a
     * piece, so a template that moved the totals above the line items changed no
     * part of the document -- the second half of the same lie that unhiding a block
     * told.
     *
     * The rail design is the one case where the order differs: bill-to, bank details
     * and payment terms travel down the coloured band rather than in the flow, so
     * they are excluded here and rendered by `railBlocks()` instead.
     *
     * @return array<int, string>
     */
    public function canvasBlocks(): array
    {
        if ($this->style !== self::STYLE_RAIL) {
            return $this->blocks;
        }

        return array_values(array_diff($this->blocks, self::RAIL_BLOCKS));
    }

    /**
     * Blocks the operator switched off, for the settings screen to show as available.
     *
     * @return array<int, string>
     */
    public function hiddenBlocks(): array
    {
        return array_values(array_diff(array_keys(self::BLOCKS), $this->blocks));
    }

    /**
     * Whether the operator is allowed to switch this block off.
     *
     * Every block is now switchable. The letterhead and the line items used to
     * be forced here and on the form, because "a document with no line items is
     * not a document"; businesses print copies without them often enough (a
     * sheet the warehouse signs, a design proof, a pro-forma without totals)
     * that forcing them was policy rather than physics. The checkbox is theirs.
     */
    public static function isRemovable(string $block): bool
    {
        return (self::BLOCKS[$block]['removable'] ?? false) === true;
    }

    /**
     * A template's stored block list, made safe.
     *
     * Three defences, in this order:
     *
     *   1. Unknown keys are dropped. A stale or hand-edited row must not be able to
     *      inject an arbitrary block name into the renderer.
     *   2. The letterhead prints first WHEN IT IS PRESENT. A company name printed
     *      halfway down the page under a table of items is a mistake no template
     *      author would ship and no drag gesture could produce deliberately. An
     *      operator who switched it off gets a document without it -- that is what
     *      the switch means -- and no ordering rule applies to nothing.
     *   3. Service blocks with nothing to say print nothing (the partials and the
     *      `$blockReady` gate handle that), so an enabled-but-empty block cannot
     *      put a heading over nothing.
     *
     * What is NOT done is re-adding the blocks the operator switched off.
     *
     * That was the behaviour here until this pass, and it made the settings screen
     * a lie twice over: unticking a section on the form could not remove it from
     * the document, so "Blocks can be shown, hidden and reordered" was true of
     * none of the three verbs. An optional block the template does not list stays
     * out; honouring it is the entire point of the control.
     *
     * Everything that IS listed keeps the operator's ordering. This is a block they
     * moved into that position on purpose; second-guessing it is how a settings
     * screen stops matching anybody's intent.
     *
     * @return array<int, string>
     */
    private function normaliseBlocks(array $blocks): array
    {
        /**
         * No list at all means the template expresses no preference.
         *
         * Distinct from an empty *result*: "nothing was stored" is the backfilled
         * and freshly-seeded case, which wants the full default order, whereas "the
         * operator ticked only these" is a list that has been filtered already and
         * must not be topped up behind their back.
         */
        if ($blocks === []) {
            $blocks = self::DEFAULT_BLOCK_ORDER;
        }

        $hadHeader = in_array('header', $blocks, true);

        $clean = [];

        foreach ($blocks as $block) {
            if (is_string($block) && isset(self::BLOCKS[$block]) && ! in_array($block, $clean, true)) {
                $clean[] = $block;
            }
        }

        // The letterhead goes first whenever it is printed at all. Both branches
        // end up the same way, which is the point: whether the template put it
        // first or somewhere else, it prints first.
        $clean = array_values(array_diff($clean, ['header']));

        if ($hadHeader) {
            array_unshift($clean, 'header');
        }

        return $clean;
    }
}
