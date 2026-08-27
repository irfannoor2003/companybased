<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignId('salesman_id')->nullable()->after('customer_id')->constrained('users')->nullOnDelete();
            $table->index('salesman_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropForeign(['salesman_id']);
            $table->dropIndex(['salesman_id']);
            $table->dropColumn('salesman_id');
        });
    }
};
