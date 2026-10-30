<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give the `title` block a home in every template row that already stores a block
 * list.
 *
 * `title` -- the "INVOICE" heading and the document number -- is a new, switchable
 * block. Templates whose `layout.blocks` was saved before it existed list the ten
 * older blocks explicitly, and a list that omits `title` means OFF (that is the
 * whole contract of the settings screen). Left alone, every template a business
 * had designed would silently stop printing its own document number the next
 * time the code deployed, with nothing on screen explaining it.
 *
 * So the backfill SPLICES `title` into stored lists, right after the letterhead,
 * which is both the default position and where `DEFAULT_BLOCK_ORDER` now puts
 * it. Rows that store no list at all need nothing: an absent list resolves to
 * the default order, which includes the block.
 *
 * Only NON-EMPTY lists are touched, and a list that already names `title` is
 * left alone, which makes the migration idempotent rather than positionally
 * fragile.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('document_templates')->orderBy('id')->get(['id', 'layout']) as $row) {
            $layout = json_decode((string) $row->layout, true);

            if (! is_array($layout)) {
                continue;
            }

            $blocks = $layout['blocks'] ?? null;

            if (! is_array($blocks) || $blocks === []) {
                // No list stored: the default order governs, and it already
                // carries `title`.
                continue;
            }

            if (in_array('title', $blocks, true)) {
                continue;
            }

            // After the letterhead, wherever it sits in the stored order -- the
            // renderer forces the letterhead to print first anyway, so position
            // one is the natural reading spot for "which document is this".
            array_splice($blocks, 1, 0, ['title']);

            $layout['blocks'] = $blocks;

            DB::table('document_templates')
                ->where('id', $row->id)
                ->update(['layout' => json_encode($layout)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('document_templates')->orderBy('id')->get(['id', 'layout']) as $row) {
            $layout = json_decode((string) $row->layout, true);

            if (! is_array($layout) || ! is_array($layout['blocks'] ?? null)) {
                continue;
            }

            $layout['blocks'] = array_values(array_filter(
                $layout['blocks'],
                fn ($block) => $block !== 'title',
            ));

            DB::table('document_templates')
                ->where('id', $row->id)
                ->update(['layout' => json_encode($layout)]);
        }
    }
};
