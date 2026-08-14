<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected array $tables = [
        'sales_invoices',
        'sales_quotes',
        'sales_orders',
        'sales_payments',
        'purchase_invoices',
        'purchase_quotes',
        'purchase_orders',
        'supplier_payments',
        'withholding_tax_receipts',
        'sales_credit_notes',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasColumn($table, 'exchange_rate')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->decimal('exchange_rate', 18, 6)->nullable()->default(1.000000);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasColumn($table, 'exchange_rate')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('exchange_rate');
                });
            }
        }
    }
};
