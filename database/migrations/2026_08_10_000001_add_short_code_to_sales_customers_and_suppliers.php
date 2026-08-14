<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_customers', function (Blueprint $table) {
            $table->string('short_code', 40)->nullable()->after('company_name');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('short_code', 40)->nullable()->after('company_name');
        });
    }

    public function down(): void
    {
        Schema::table('sales_customers', function (Blueprint $table) {
            $table->dropColumn('short_code');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('short_code');
        });
    }
};
