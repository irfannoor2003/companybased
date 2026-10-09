<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quotes are dispatched through the Sales mail identity, which can be configured
 * to send from the assigned salesperson's own address
 * (mail.sales_use_salesman_address). SalesQuoteMailable reads $quote->salesman to
 * honour that setting, but only sales_orders carried a salesman_id, so the toggle
 * was silently inert for quotations.
 *
 * Mirrors 2026_08_14_000001_add_salesman_id_to_sales_orders_table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_quotes', function (Blueprint $table) {
            $table->foreignId('salesman_id')->nullable()->after('customer_id')->constrained('users')->nullOnDelete();
            $table->index('salesman_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_quotes', function (Blueprint $table) {
            $table->dropForeign(['salesman_id']);
            $table->dropIndex(['salesman_id']);
            $table->dropColumn('salesman_id');
        });
    }
};
