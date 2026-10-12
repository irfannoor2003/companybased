<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Weighted-average inventory valuation.
 *
 * Cost of goods sold was recognised at the product's `cost_price`, a figure someone
 * typed onto the product record. That is only correct if every purchase of that
 * product happened at the same price. A business that buys the same item at 100
 * and then at 140 is carrying stock whose value is neither, and the margin it
 * reports on the sale in between is a fiction -- understated while prices are
 * rising, overstated while they fall.
 *
 * What goes wrong is not merely that the number is approximate. Because the same
 * invented figure relieves the Inventory account as it debits cost of goods sold,
 * the balance sheet moves in step and the two statements still agree with each
 * other. Nothing looks wrong. The error is simply invisible until someone reads
 * the purchase invoices.
 *
 * A moving average fixes it, and it is the right method here rather than FIFO:
 *
 *   On a receipt of Q units at cost C, against Q0 on hand at average A:
 *
 *       A' = (Q0 x A + Q x C) / (Q0 + Q)
 *
 *   On a sale, the average does NOT change -- Q units leave at A, and the same A
 *   is credited to Inventory. Weighted average does not consume cost layers, so
 *   there is no per-layer state to maintain and no way for the layers to drift out
 *   of step with the stock rows.
 *
 * The average is held on the inventory item rather than the product, because that
 * is the level stock is actually tracked at, and a product can be stocked in several
 * warehouses.
 *
 * Backfilled from `cost_price` so existing stock is valued at what the business was
 * already reporting rather than at zero. A business that has never received stock
 * through a tracked movement keeps its hand-set cost price as the opening average,
 * which is the closest honest answer available.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            // Four decimals: unit costs of 0.0025 are real in packaging and
            // chemicals, and rounding to two would flatten them.
            $table->decimal('moving_average_cost', 18, 4)->nullable()->after('product_id');
        });

        DB::table('inventory_items')
            ->whereNull('moving_average_cost')
            ->whereNotNull('product_id')
            ->update([
                'moving_average_cost' => DB::raw('(SELECT cost_price FROM products WHERE products.id = inventory_items.product_id)'),
            ]);

        DB::table('inventory_items')
            ->whereNull('moving_average_cost')
            ->update(['moving_average_cost' => '0']);
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('moving_average_cost');
        });
    }
};
