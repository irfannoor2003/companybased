<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report what a production run actually produced.
 *
 * Every production order recorded only what it was ASKED to produce. On completion
 * the controller wrote `quantity_used = quantity_required` over the component lines
 * with a single bulk update, so the shop floor could not tell it what was really
 * consumed. That is not a reporting gap, it is an accounting one, in three places
 * at once:
 *
 *   - Components consumed at less than the BOM quantity, and stock written off,
 *     were recorded as consumed in full. The inventory loss vanished.
 *   - Finished output was hardcoded to `order->quantity`. A run that produced 90
 *     good units out of 100 attempted posted 100 into stock.
 *   - Nothing anywhere recorded scrap, so the cost of the material that went into
 *     unusable output had no home. It stayed in the finished goods' cost, quietly
 *     inflating the margin on what did sell.
 *
 * So this adds the two numbers the sheet floor can actually report:
 *
 *   quantity_good     units of saleable output -- this, and only this, is received
 *                     into stock.
 *   quantity_scrap    units produced but rejected.
 *
 * plus per-component `quantity_scrapped` with a reason, because "12 units of
 * component 4 were scrapped" and "12 units were rejected at final inspection" are
 * different problems needing different fixes.
 *
 * `quantity_good` and `quantity_scrap` are nullable so an existing completed order
 * is not retroactively reinterpreted: a null good quantity means "not reported",
 * which the posting path treats as the full ordered quantity, exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_production_orders', function (Blueprint $table) {
            $table->decimal('quantity_good', 14, 3)->nullable()->after('quantity')
                ->comment('Saleable output actually produced. Null means not reported.');

            $table->decimal('quantity_scrap', 14, 3)->nullable()->default(0)
                ->comment('Output produced but rejected.');

            $table->dateTime('started_at')->nullable()->after('status');
            $table->dateTime('completed_at')->nullable()->after('started_at');
        });

        Schema::table('inventory_production_order_items', function (Blueprint $table) {
            $table->decimal('quantity_scrapped', 14, 3)->nullable()->default(0)
                ->comment('Component units lost to scrap, as distinct from consumed.');

            $table->string('scrap_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_production_orders', function (Blueprint $table) {
            $table->dropColumn(['quantity_good', 'quantity_scrap', 'started_at', 'completed_at']);
        });

        Schema::table('inventory_production_order_items', function (Blueprint $table) {
            $table->dropColumn(['quantity_scrapped', 'scrap_reason']);
        });
    }
};