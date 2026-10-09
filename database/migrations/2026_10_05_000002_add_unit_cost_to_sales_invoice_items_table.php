<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot the cost price onto each sales invoice line.
 *
 * Selling from stock posted revenue but no cost of goods sold, and nothing left
 * Inventory. For any business holding stock that means the profit and loss is
 * overstated by the cost of everything sold, and inventory on the balance sheet
 * never falls however much is shipped -- the two errors partly cancelling into a
 * figure that looks plausible and is wrong.
 *
 * The cost is *snapshotted* rather than read from the product at posting time on
 * purpose. Repricing a product tomorrow must not silently rewrite the cost of
 * goods sold on an invoice issued last quarter, or last month's margin changes
 * under the accountant's feet with no transaction to show for it.
 *
 * Nullable: a line with no product (a service, or a manually keyed line) has no
 * cost, and is treated as a zero-cost sale rather than being blocked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoice_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 14, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoice_items', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};