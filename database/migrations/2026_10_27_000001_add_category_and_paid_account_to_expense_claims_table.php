<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            /**
             * The category a claim belongs to, chosen from the admin-created
             * Expense Categories. Nullable: rows written by older deployments
             * carry only the legacy `expense_type` string, and those must keep
             * loading without a backfill.
             */
            $table->foreignId('expense_category_id')
                ->nullable()
                ->after('expense_type')
                ->constrained('expense_categories')
                ->nullOnDelete();

            /**
             * The bank account — or petty-cash account — the claim is paid from.
             * Empty means "reimburse outside the tracked accounts", which is the
             * honest description of a claim nobody named an account for.
             */
            $table->foreignId('bank_account_id')
                ->nullable()
                ->after('currency')
                ->constrained('bank_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->dropForeign(['expense_category_id']);
            $table->dropColumn('expense_category_id');
            $table->dropForeign(['bank_account_id']);
            $table->dropColumn('bank_account_id');
        });
    }
};
