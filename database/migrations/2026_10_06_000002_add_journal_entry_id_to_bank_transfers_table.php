<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bank transfer's journal reference.
 *
 * Split out from 2026_10_06_000001 rather than added there: a transfer has no
 * journal of its own -- its two legs live on the bank_transactions it created --
 * so the only way to find the journal a transfer produced is to record it on the
 * transfer. Without it, reversing a completed transfer could not locate the entry
 * it needed to void, and the balance sheet kept a transfer that the bank ledger
 * no longer had.
 *
 * Guarded, because the earlier migration may already have added it on some
 * environments and a bare ADD COLUMN would fail there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bank_transfers', 'journal_entry_id')) {
            Schema::table('bank_transfers', function (Blueprint $table) {
                $table->foreignId('journal_entry_id')
                    ->nullable()
                    ->constrained('journal_entries')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bank_transfers', 'journal_entry_id')) {
            Schema::table('bank_transfers', function (Blueprint $table) {
                $table->dropConstrainedForeignId('journal_entry_id');
            });
        }
    }
};