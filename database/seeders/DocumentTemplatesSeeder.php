<?php

namespace Database\Seeders;

use App\Models\DocumentTemplate;
use App\Support\PdfLayout;
use Illuminate\Database\Seeder;

/**
 * The document templates a fresh deployment starts with.
 *
 * THE REASON THIS EXISTS
 *
 * `document_templates` had ten rows on the development database and no seeder at
 * all. They were inserted by hand. A genuinely fresh install therefore opened
 * Settings -> Templates on an empty page: nothing to choose, nothing to make
 * default, and no indication that templates existed at all. The backfill migration
 * `2026_10_14_000001` only fills in `style` and `blocks` for rows that are already
 * there, so on an empty table it does nothing.
 *
 * An empty default is survivable -- `PdfLayout::forType()` falls back to the built-in
 * rail design and every document still prints. But it makes the template subsystem
 * look absent, which is the same "convincing lie" `PdfLayout` was written to close:
 * a settings screen that is there and does nothing reads as a bug in the printer.
 *
 * WHAT IS AND IS NOT SEEDED
 *
 * One curated template per document type, in the SigmaShop design, as the default.
 * The ported flat layout is the single house design for EVERY document the company
 * prints -- invoices, quotes, orders, delivery notes, credit notes, purchase orders,
 * bills and receipts. Every type also keeps a Classic rail preset (and Modern Pro)
 * so the picker offers a real choice rather than one identical row. All presets are
 * `is_system` so they cannot be deleted out from under a document that uses them.
 *
 * The ported designs store no colours, so they follow the superadmin's configured
 * brand colour; the Classic presets keep their original fixed palette.
 *
 * NOT seeded: purchase quotes, debit notes or recurring invoices. Those three alias
 * onto a template they resemble (`PdfLayout::TYPE_ALIASES`) rather than having a row
 * of their own, and seeding them would put three more rows on a screen where the
 * choice has no effect -- a worse lie than their absence.
 *
 * IDEMPOTENT
 *
 * Keyed on `slug` and written with `updateOrCreate`, so `db:seed` is safe to re-run
 * on a deployment that already has templates. It never touches a row this seeder did
 * not create, and it never overwrites `is_default` on a row an administrator has
 * already pointed at something else -- a fresh `db:seed` must not quietly change
 * which template a business's invoices print with.
 */
class DocumentTemplatesSeeder extends Seeder
{
    /**
     * The document types that get their own template row.
     *
     * Matches `document_templates.type`, an enum of eight values. The three types
     * missing from this list are the aliases.
     *
     * @var array<int, string>
     */
    private const TYPES = [
        'invoice',
        'quote',
        'order',
        'delivery_note',
        'credit_note',
        'purchase_order',
        'purchase_invoice',
        'receipt',
    ];

    public function run(): void
    {
        foreach ($this->definitions() as $definition) {
            $this->upsert($definition);
        }
    }

    /**
     * Curated presets, one per type.
     *
     * NO preset stores a colour. `PdfLayout` fills every empty palette from the
     * superadmin's settings (branding.primary_color / branding.accent_color), so a
     * brand change recolours every document -- not just the ported designs. The
     * operator can still set a deliberate override on a custom template; seeded
     * presets deliberately stay brand-driven.
     *
     * @return array<int, array<string, mixed>>
     */
    private function definitions(): array
    {
        $definitions = [];

        // SigmaShop is the house design for EVERY document type. Classic rail and
        // Modern Pro stay available as alternatives, but no document type silently
        // prints in a different design from the rest.
        $portedDefaults = array_fill_keys(self::TYPES, PdfLayout::STYLE_SIGMASHOP);

        foreach (self::TYPES as $type) {
            $definitions[] = [
                'name' => 'Classic '.ucwords(str_replace('_', ' ', $type)),
                'slug' => 'classic-'.str_replace('_', '-', $type),
                'type' => $type,
                'style' => PdfLayout::STYLE_RAIL,
                'description' => 'The classic ' .str_replace('_', ' ', $type).' design: a full-height brand band down the left of every page.',
                'colors' => [],
                'layout' => $this->layout(PdfLayout::STYLE_RAIL),
                'is_default' => ! array_key_exists($type, $portedDefaults),
                'is_system' => true,
            ];
        }

        // Design families ported from the inventory project, offered on every
        // document type so the same choices are available wherever one is edited.
        foreach (self::TYPES as $type) {
            $label = ucwords(str_replace('_', ' ', $type));

            $definitions[] = [
                'name' => 'Modern Pro '.$label,
                'slug' => 'modern-pro-'.str_replace('_', '-', $type),
                'type' => $type,
                'style' => PdfLayout::STYLE_MODERN_PRO,
                'description' => 'Modern design ported from the inventory project: pill title, card sections, coloured totals.',
                // No hardcoded colour: the design follows the company colour the
                // superadmin configures in Settings -> Company.
                'colors' => [],
                'layout' => $this->layout(PdfLayout::STYLE_MODERN_PRO),
                'is_default' => ($portedDefaults[$type] ?? null) === PdfLayout::STYLE_MODERN_PRO,
                'is_system' => true,
            ];

            $definitions[] = [
                'name' => 'SigmaShop '.$label,
                'slug' => 'sigmashop-'.str_replace('_', '-', $type),
                'type' => $type,
                'style' => PdfLayout::STYLE_SIGMASHOP,
                'description' => 'Flat SigmaShop design ported from the inventory project: coloured table header and right-floated totals.',
                // Follows the superadmin's configured company colour.
                'colors' => [],
                // Only the sigma block: it renders the complete real layout.
                'layout' => $this->layoutWithBlocks(PdfLayout::STYLE_SIGMASHOP, ['sigma']),
                'is_default' => ($portedDefaults[$type] ?? null) === PdfLayout::STYLE_SIGMASHOP,
                'is_system' => true,
            ];
        }

        // A second option where a business is most likely to want one.
        foreach (['invoice', 'quote'] as $type) {
            $definitions[] = [
                'name' => 'Modern '.ucwords(str_replace('_', ' ', $type)),
                'slug' => 'modern-'.str_replace('_', '-', $type),
                'type' => $type,
                'style' => PdfLayout::STYLE_MODERN,
                'description' => 'A full-width brand masthead across the top of every page.',
                'colors' => [],
                'layout' => $this->layout(PdfLayout::STYLE_MODERN),
                'is_default' => false,
                'is_system' => true,
            ];
        }

        /*
         * Two showcase designs, both "modern", deliberately built the two ways
         * the template editor supports. They are non-default so a fresh install
         * still prints the byte-identical classic design until someone picks one.
         *
         *   - Modern Flow demonstrates DRAG-AND-DROP: no custom code at all, just
         *     a block order an operator could have reached by dragging the rows.
         *     It exists so the two mechanisms are visibly different.
         *
         *   - Modern Signature demonstrates CUSTOM CODE: a normal block order,
         *     but header HTML, footer HTML and a stylesheet injected last in the
         *     cascade. No pixels are positioned; the operator's CSS restyles the
         *     same tested blocks.
         */
        $definitions[] = [
            'name' => 'Modern Flow Invoice',
            'slug' => 'modern-flow-invoice',
            'type' => 'invoice',
            'style' => PdfLayout::STYLE_MODERN,
            'description' => 'Modern masthead with the sections reordered by dragging: notes and signature sit directly under the totals.',
            'colors' => [],
            'layout' => $this->layoutWithBlocks(PdfLayout::STYLE_MODERN, [
                'header', 'title', 'party', 'meta', 'items', 'totals',
                'notes', 'signature', 'bank', 'footer',
            ]),
            'is_default' => false,
            'is_system' => true,
        ];

        $definitions[] = [
            'name' => 'Modern Signature Invoice',
            'slug' => 'modern-signature-invoice',
            'type' => 'invoice',
            'style' => PdfLayout::STYLE_MODERN,
            'description' => 'Modern masthead restyled with custom header HTML, footer HTML and a CSS override sheet.',
            // No fixed palette: even this showcase follows the superadmin's brand
            // colour. Its custom CSS changes typography and spacing, not the hue.
            'colors' => [],
            'layout' => $this->layout(PdfLayout::STYLE_MODERN),
            'header_html' => '<p class="modern-ribbon">CERTIFIED LOCAL PARTNER | INVOICE ENCLOSED</p>',
            'footer_html' => '<p class="modern-signature-note">Thank you for choosing us. Queries within 14 days of issue.</p>',
            'css' => <<<'CSS'
                .masthead { border-bottom-width: 3px; }
                .masthead-name { letter-spacing: 0.16em; text-transform: uppercase; }
                .modern-ribbon {
                    margin: 0 0 4px; font-size: 8px; font-weight: bold;
                    letter-spacing: 0.14em; text-transform: uppercase;
                }
                table.items thead th { border-bottom-width: 2px; }
                table.totals tr.grand td { font-size: 13px; }
                .modern-signature-note {
                    margin: 6px 0 0; font-size: 8.5px; font-style: italic;
                    text-align: center;
                }
                CSS,
            'is_default' => false,
            'is_system' => true,
        ];

        return $definitions;
    }

    /**
     * The stored layout blob with an explicit block order, for the showcase
     * template that demonstrates a drag-reordered sequence.
     *
     * @param  array<int,string>  $blocks
     * @return array<string,mixed>
     */
    private function layoutWithBlocks(string $style, array $blocks): array
    {
        $layout = $this->layout($style);
        $layout['blocks'] = $blocks;

        return $layout;
    }

    /**
     * The stored layout blob.
     *
     * Built through `PdfLayout` rather than written out, so the seeder stores exactly
     * what the settings screen would have stored for the same choices. The two cannot
     * disagree, which is what makes the block order here a real default rather than a
     * hopeful one.
     *
     * `style` is written into the JSON as well as the column. The column is what
     * `PdfLayout::forType()` reads; the JSON copy is the fallback for a row written
     * before the column existed, so the two are kept in step rather than left to
     * drift apart.
     *
     * @return array<string, mixed>
     */
    private function layout(string $style): array
    {
        return [
            'header' => 'left',
            'show_logo' => true,
            'show_tax' => true,
            'style' => $style,
            'blocks' => (new PdfLayout($style))->blocks(),
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function upsert(array $definition): void
    {
        $existing = DocumentTemplate::where('slug', $definition['slug'])->first();

        /*
         * One system default per type. This must run on EVERY seed, not only
         * when the row is new: after switching which preset is default, the
         * previously-default preset still exists and would otherwise keep its
         * flag, leaving two defaults and making resolution a coin flip.
         *
         * A custom (non-system) choice is never demoted -- that belongs to the
         * operator, and reseeding must not take it away.
         */
        if (! empty($definition['is_default'])) {
            $customDefault = DocumentTemplate::where('type', $definition['type'])
                ->where('is_default', true)
                ->where('is_system', false)
                ->exists();

            if (! $customDefault) {
                DocumentTemplate::where('type', $definition['type'])
                    ->where('is_system', true)
                    ->where('slug', '!=', $definition['slug'])
                    ->update(['is_default' => false]);
            }
        }

        if ($existing === null) {
            DocumentTemplate::create($definition);

            return;
        }

        /**
         * A row an administrator has since made default keeps being default.
         *
         * Re-seeding is a routine thing to do after an upgrade. If it silently moved
         * the default off whatever the business had chosen, an operator would come
         * back one morning to invoices in a design nobody selected and no record of
         * when it changed. Everything else about the preset is refreshed.
         */
        if ($existing->is_default && ! $definition['is_default']) {
            unset($definition['is_default']);
        }

        $existing->fill($definition)->save();
    }
}
