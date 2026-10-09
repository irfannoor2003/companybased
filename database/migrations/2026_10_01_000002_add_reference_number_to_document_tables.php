<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A manual / external document number.
 *
 * Businesses issuing an invoice from a system they already used ("INV-OS-4471")
 * need that number to appear on the document, because it is the number their
 * customer, their accountant and their own filing reference all quote.
 *
 * The system-generated `number` column is deliberately left untouched: it is
 * the internal identity used by counters, PDF filenames, exports and internal
 * links, and overwriting it with a user string would break the sequence and
 * make two different documents able to collide. So each document now carries
 * both: the sequential internal number and the user-supplied reference.
 *
 * Unique per table and nullable: MySQL permits any number of NULLs, so
 * documents without a manual number are unconstrained, while two documents
 * cannot claim the same external reference.
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $tables = [
        'sales_quotes',
        'sales_orders',
        'sales_invoices',
        'sales_credit_notes',
        'sales_delivery_notes',
        'sales_payments',
        'purchase_quotes',
        'purchase_orders',
        'purchase_invoices',
        'debit_notes',
        'supplier_payments',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'reference_number')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('reference_number', 120)->nullable()->after('number');
                $blueprint->unique('reference_number', $blueprint->getTable().'_reference_number_unique');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'reference_number')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropUnique($blueprint->getTable().'_reference_number_unique');
                $blueprint->dropColumn('reference_number');
            });
        }
    }
};
