<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A general-ledger account per purchase-bill line.
 *
 * Without this every bill posted entirely to Cost of Goods Sold, so the profit and
 * loss statement could not answer the only question anyone asks of it: which
 * expenses went up. Rent, utilities and salary all landed in the same line.
 *
 * This is the account the *net* value of the line is charged to. The tax on the
 * line is still recoverable input tax, and the gross is still what the supplier is
 * owed, so those three figures do not change -- only the account the net lands in.
 *
 * Nullable on purpose: existing rows and bills raised without an account keep
 * working and fall back to Cost of Goods Sold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->foreignId('account_id')
                ->nullable()
                ->after('product_id')
                ->constrained('accounts')
                ->nullOnDelete();

            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropIndex(['account_id']);
            $table->dropColumn('account_id');
        });
    }
};