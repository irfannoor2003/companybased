<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give `table` and `body` a home in every stored template layout.
 *
 * `table` renders statements, list exports and ledger reports; `body` renders a
 * document with its own shape (a payslip). Both are new, switchable blocks. Rows
 * whose `layout.blocks` was saved before they existed list the older blocks
 * explicitly, and a list that omits a block means OFF -- left alone, a restyled
 * template would silently lose its statement rows or its payslip.
 *
 * Only non-empty lists are edited, and a list that already names a block is left
 * alone, so the migration is idempotent and preserves an operator's own order.
 */
return new class extends Migration
{
    /** @var array<string,string> block => block it should follow */
    private const INSERT_AFTER = [
        'body' => 'meta',
        'table' => 'items',
    ];

    public function up(): void
    {
        foreach (DB::table('document_templates')->orderBy('id')->get(['id', 'layout']) as $row) {
            $layout = json_decode((string) $row->layout, true);

            if (! is_array($layout) || ! is_array($layout['blocks'] ?? null) || $layout['blocks'] === []) {
                continue;
            }

            $blocks = $layout['blocks'];
            $changed = false;

            foreach (self::INSERT_AFTER as $block => $after) {
                if (in_array($block, $blocks, true)) {
                    continue;
                }

                $position = array_search($after, $blocks, true);
                $position = $position === false ? count($blocks) : $position + 1;

                array_splice($blocks, $position, 0, [$block]);
                $changed = true;
            }

            if (! $changed) {
                continue;
            }

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
                fn ($block) => ! in_array($block, array_keys(self::INSERT_AFTER), true),
            ));

            DB::table('document_templates')
                ->where('id', $row->id)
                ->update(['layout' => json_encode($layout)]);
        }
    }
};
