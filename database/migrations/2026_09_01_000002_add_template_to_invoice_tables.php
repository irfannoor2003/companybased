<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->string('template', 30)->default('classic')->after('status');
        });

        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->string('template', 30)->default('classic')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn('template');
        });

        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropColumn('template');
        });
    }
};
