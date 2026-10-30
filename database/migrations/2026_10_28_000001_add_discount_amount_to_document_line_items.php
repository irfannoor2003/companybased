<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every line-item table the shared document arithmetic prices carries a
     * discount percentage. They now also carry the discount as a SPECIFIC
     * AMOUNT, chosen from the line editor's "Percent / Fixed amount" select.
     *
     * The two are stored together but never both applied: a line records its
     * percentage discount with `discount_percent` and zeroes `discount_amount`,
     * or records a fixed amount off the line and zeroes the percentage. The
     * effective discount is resolved in DocumentItems from whichever is set.
     *
     * @var array<int, string>
     */
    private const LINE_ITEM_TABLES = [
        'sales_quote_items',
        'sales_order_items',
        'sales_invoice_items',
        'sales_credit_note_items',
        'sales_recurring_invoice_items',
        'purchase_quote_items',
        'purchase_order_items',
        'purchase_invoice_items',
        'debit_note_items',
    ];

    public function up(): void
    {
        foreach (self::LINE_ITEM_TABLES as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'discount_amount')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->decimal('discount_amount', 14, 2)->default(0);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::LINE_ITEM_TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'discount_amount')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropColumn('discount_amount');
                });
            }
        }
    }
};
