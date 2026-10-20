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
 * One curated template per document type, in the rail design, as the default. Rail
 * because it is what the renderer prints today, so a fresh install's output is
 * byte-identical to the deployment this replaces; a business then picks a different
 * design deliberately rather than discovering one at random on its first invoice.
 *
 * A second, non-default template is seeded for the two document types customers
 * actually ask to restyle -- invoices and quotes -- so the picker offers a choice
 * rather than a list of identical rows. Those are marked `is_system` so they cannot
 * be deleted out from under a document that references them, same as the defaults.
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
     * Colours are the design defaults rather than the company's brand colour, which
     * the renderer already mixes in for the frame. Seeding a fixed palette here means
     * every fresh install looks the same and a brand change stays a branding change
     * rather than becoming a document change too.
     *
     * @return array<int, array<string, mixed>>
     */
    private function definitions(): array
    {
        $definitions = [];

        foreach (self::TYPES as $type) {
            $definitions[] = [
                'name' => 'Classic '.ucwords(str_replace('_', ' ', $type)),
                'slug' => 'classic-'.str_replace('_', '-', $type),
                'type' => $type,
                'style' => PdfLayout::STYLE_RAIL,
                'description' => 'The default '.str_replace('_', ' ', $type).' design: a full-height brand band down the left of every page.',
                'colors' => $this->colorsFor($type),
                'layout' => $this->layout(PdfLayout::STYLE_RAIL),
                'is_default' => true,
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
                'colors' => $this->colorsFor($type),
                'layout' => $this->layout(PdfLayout::STYLE_MODERN),
                'is_default' => false,
                'is_system' => true,
            ];
        }

        return $definitions;
    }

    /**
     * The colours a document type prints in.
     *
     * @return array<string, string>
     */
    private function colorsFor(string $type): array
    {
        // A delivery note and a receipt carry no money, so the emerald accent reads
        // as "settled / no charge" rather than as another brand colour to decode.
        $accent = in_array($type, ['receipt', 'delivery_note'], true) ? '#059669' : '#2563eb';

        return [
            'primary' => '#1e3a5f',
            'accent' => $accent,
            'text' => '#1f2937',
        ];
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
