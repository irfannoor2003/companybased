<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_customers', function (Blueprint $table) {
            $table->unique('short_code');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->unique('short_code');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropUnique('suppliers_short_code_unique');
        });

        Schema::table('sales_customers', function (Blueprint $table) {
            $table->dropUnique('sales_customers_short_code_unique');
        });
    }
};