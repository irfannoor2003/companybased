<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give the `receipt` block a home in every stored receipt-template layout.
 *
 * `receipt` is the new payment-amount panel: the one figure a proof of payment
 * carries, printed where an invoice would print its line items and totals. Rows
 * whose `layout.blocks` was saved before the block existed list the older blocks
 * explicitly, and a list that omits a block means OFF. Left alone, every
 * business's receipt template would silently lose its amount on the next deploy.
 *
 * Only receipt rows are touched, only non-empty lists are edited, and a list that
 * already names `receipt` is left alone -- so the migration is idempotent and
 * cannot overwrite a list an operator has since rearranged. Templates with no
 * stored list resolve to DEFAULT_BLOCK_ORDER, which already carries the block.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('document_templates')->where('type', 'receipt')->orderBy('id')->get(['id', 'layout']) as $row) {
            $layout = json_decode((string) $row->layout, true);

            if (! is_array($layout) || ! is_array($layout['blocks'] ?? null) || $layout['blocks'] === []) {
                continue;
            }

            $blocks = $layout['blocks'];

            if (in_array('receipt', $blocks, true)) {
                continue;
            }

            // Right after the totals (which a receipt suppresses), so the amount
            // lands in the same reading position the default order puts it.
            $position = array_search('totals', $blocks, true);
            $position = $position === false ? count($blocks) : $position + 1;

            array_splice($blocks, $position, 0, ['receipt']);

            $layout['blocks'] = $blocks;

            DB::table('document_templates')
                ->where('id', $row->id)
                ->update(['layout' => json_encode($layout)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('document_templates')->where('type', 'receipt')->orderBy('id')->get(['id', 'layout']) as $row) {
            $layout = json_decode((string) $row->layout, true);

            if (! is_array($layout) || ! is_array($layout['blocks'] ?? null)) {
                continue;
            }

            $layout['blocks'] = array_values(array_filter(
                $layout['blocks'],
                fn ($block) => $block !== 'receipt',
            ));

            DB::table('document_templates')
                ->where('id', $row->id)
                ->update(['layout' => json_encode($layout)]);
        }
    }
};
