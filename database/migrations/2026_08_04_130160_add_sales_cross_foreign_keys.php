<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_quotes', function (Blueprint $table) {
            $table->foreign('converted_to_order_id')->references('id')->on('sales_orders')->nullOnDelete();
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreign('quote_id')->references('id')->on('sales_quotes')->nullOnDelete();
        });

        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->foreign('order_id')->references('id')->on('sales_orders')->nullOnDelete();
        });

        Schema::table('sales_credit_notes', function (Blueprint $table) {
            $table->foreign('invoice_id')->references('id')->on('sales_invoices')->nullOnDelete();
        });

        Schema::table('sales_delivery_notes', function (Blueprint $table) {
            $table->foreign('order_id')->references('id')->on('sales_orders')->nullOnDelete();
        });

        Schema::table('withholding_tax_receipts', function (Blueprint $table) {
            $table->foreign('invoice_id')->references('id')->on('sales_invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_quotes', function (Blueprint $table) {
            $table->dropForeign(['converted_to_order_id']);
        });
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropForeign(['quote_id']);
        });
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
        });
        Schema::table('sales_credit_notes', function (Blueprint $table) {
            $table->dropForeign(['invoice_id']);
        });
        Schema::table('sales_delivery_notes', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
        });
        Schema::table('withholding_tax_receipts', function (Blueprint $table) {
            $table->dropForeign(['invoice_id']);
        });
    }
};
