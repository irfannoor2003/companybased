<?php

use App\Support\PdfLayout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make a document template actually describe a document.
 *
 * `document_templates` has carried a `layout` JSON blob since 2026-08-14, holding only
 * `header`, `show_logo` and `show_tax`. Nothing read it: the renderer hard-coded one
 * design, so every template produced identical output and Settings -> Templates was a
 * convincing lie.
 *
 * Two keys are added, stored inside the existing `layout` column rather than as new
 * columns, because they are part of the same concept and `layout` is already JSON:
 *
 *   style   which visual treatment to print with
 *   blocks  which pieces of the document, in which order
 *
 * `style` gets its own index-free column instead, because it is the one value read on
 * every single PDF render and is worth being able to filter and report on without
 * decoding JSON.
 *
 * The backfill maps the templates that already exist onto real styles rather than
 * leaving every one of them on the default:
 *
 *   "Modern *" -> modern    (the masthead design these were named for)
 *   everything -> rail      (what the renderer actually prints today)
 *
 * A deployment that chose "Modern Invoice" gets a masthead; everybody else is on the
 * rail, unchanged. Nobody's printed output moves except the people who had already
 * asked for Modern and been ignored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->string('style', 32)->default('rail')->after('type');
            $table->index('style');
        });

        $rows = DB::table('document_templates')->select('id', 'name', 'layout')->get();

        foreach ($rows as $row) {
            $layout = json_decode((string) $row->layout, true);
            $layout = is_array($layout) ? $layout : [];

            $layout['style'] = str_contains(strtolower((string) $row->name), 'modern')
                ? 'modern'
                : 'rail';

            // No stored order yet, so the print order is the default one.
            $layout['blocks'] = PdfLayout::DEFAULT_BLOCK_ORDER;

            DB::table('document_templates')
                ->where('id', $row->id)
                ->update([
                    'style' => $layout['style'],
                    'layout' => json_encode($layout),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropIndex(['style']);
            $table->dropColumn('style');
        });
    }
};
