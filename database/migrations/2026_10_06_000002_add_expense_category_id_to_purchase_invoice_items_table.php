<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->foreignId('expense_category_id')
                ->nullable()
                ->after('account_id')
                ->constrained('expense_categories')
                ->nullOnDelete();

            $table->index('expense_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->dropForeign(['expense_category_id']);
            $table->dropIndex(['expense_category_id']);
            $table->dropColumn('expense_category_id');
        });
    }
};