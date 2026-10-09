<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * Which ledger account each bank account represents.
         *
         * Without this a bank account is a list of transactions with no connection
         * to the trial balance: a transfer between two accounts moves money in the
         * bank ledger while the balance sheet shows neither side changing, so the
         * two disagree and nothing reveals it.
         */
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->foreignId('account_id')
                ->nullable()
                ->after('currency')
                ->constrained('accounts')
                ->nullOnDelete();
        });

        /**
         * Stamped so an edited or deleted manual bank entry can void its own
         * journal instead of leaving an orphan on the balance sheet.
         *
         * bank_transfers gets the same column in a separate migration.
         */
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')
                ->nullable()
                ->after('reference_id')
                ->constrained('journal_entries')
                ->nullOnDelete();
        });

        // The mapping is consulted on every transfer, so index the join.
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->index(['bank_account_id', 'journal_entry_id'], 'bank_txn_account_journal_index');
        });
    }

    public function down(): void
    {

        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropIndex('bank_txn_account_journal_index');
            $table->dropConstrainedForeignId('journal_entry_id');
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
        });
    }
};