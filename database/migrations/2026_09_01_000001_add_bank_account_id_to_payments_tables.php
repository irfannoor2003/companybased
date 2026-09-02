<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('bank_account_id')->nullable()->after('invoice_id');
            $table->foreign('bank_account_id')->references('id')->on('bank_accounts')->nullOnDelete();
            $table->index('bank_account_id');
        });

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('bank_account_id')->nullable()->after('invoice_id');
            $table->foreign('bank_account_id')->references('id')->on('bank_accounts')->nullOnDelete();
            $table->index('bank_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_payments', function (Blueprint $table) {
            $table->dropForeign(['bank_account_id']);
            $table->dropIndex(['bank_account_id']);
            $table->dropColumn('bank_account_id');
        });

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropForeign(['bank_account_id']);
            $table->dropIndex(['bank_account_id']);
            $table->dropColumn('bank_account_id');
        });
    }
};
