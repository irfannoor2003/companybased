<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give the tables that already carry a currency an exchange rate to go with it.
 *
 * 2026_10_06_000003 made journal lines able to express a foreign amount. Documents
 * that already had both currency and exchange_rate could supply them, and posted
 * correctly.
 *
 * These tables have a `currency` column and no rate. Any posting path reading them
 * therefore found a null rate and fell back to 1 -- which posts the face value and
 * is precisely the understatement the previous migration removed. A payroll run
 * raised in USD would have credited Salaries and Wages with 1000 instead of the
 * base-currency equivalent.
 *
 * Backfilled at 1 rather than left null: for every row already on disk the ledger
 * holds the face value, so recording rate 1 describes what was actually posted
 * instead of pretending an unknown rate. New documents overwrite it on save.
 */
return new class extends Migration
{
    /**
     * Tables with a currency column that post to the ledger and had no rate.
     *
     * @var array<int, string>
     */
    private array $tables = [
        'payroll_runs',
        'pos_sales',
        'expense_claims',
        'tax_returns',
        'withholding_tax_receipts',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table)
                && Schema::hasColumn($table, 'currency')
                && ! Schema::hasColumn($table, 'exchange_rate')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->decimal('exchange_rate', 18, 8)->nullable()->after('currency');
                });

                DB::table($table)->whereNull('exchange_rate')->update(['exchange_rate' => 1]);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table)
                && Schema::hasColumn($table, 'exchange_rate')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('exchange_rate');
                });
            }
        }
    }
};