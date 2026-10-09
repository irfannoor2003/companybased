<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make the journal line able to express a foreign-currency amount.
 *
 * journal_entry_items held only debit and credit, both single-currency decimals.
 * Every posting therefore wrote the document's *face value* into the ledger, and
 * the trial balance summed those face values as though they were all base currency.
 * A USD 100 invoice at 250 PKR/USD landed in Accounts Receivable as 100 and the
 * balance sheet reported 100 -- a 250x understatement that no report could reveal,
 * because nothing recorded that the amount had ever been foreign.
 *
 * This adds the three things needed to fix it:
 *
 *   currency       the currency the transaction actually happened in
 *   exchange_rate  the rate snapshotted on the source document
 *   original_*     the face-value debit and credit, kept before conversion
 *
 * Debits and credits stay in base currency, so every existing report, aggregation
 * and total keeps working unchanged. That matters for the rows already on disk:
 * defaulting currency to the base currency and rate to 1 makes the new columns
 * describe existing lines correctly rather than describing them wrongly.
 *
 * Original amounts are kept rather than discarded because revaluation needs them.
 * Without the original figure on the line, an unrealised gain at period close
 * cannot be computed -- there is nothing to compare the closing rate against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entry_items', function (Blueprint $table) {
            $table->string('currency', 8)->nullable()->after('credit');
            $table->decimal('exchange_rate', 18, 8)->nullable()->after('currency');
            $table->decimal('original_debit', 18, 4)->nullable()->after('exchange_rate');
            $table->decimal('original_credit', 18, 4)->nullable()->after('original_debit');

            // Aggregation groups by account and currency, so this is the hot path.
            $table->index(['account_id', 'currency'], 'journal_items_account_currency_index');
        });

        /**
         * Backfill existing lines as base currency at rate 1.
         *
         * Without this they would read as NULL and be treated as unknown by the
         * reporting layer -- which is worse than assuming base currency, because
         * the line demonstrably was posted through a single-currency ledger.
         */
        DB::table('journal_entry_items')->update([
            'currency' => base_currency(),
            'exchange_rate' => 1,
            'original_debit' => DB::raw('debit'),
            'original_credit' => DB::raw('credit'),
        ]);
    }

/**
 * Restore the state before the currency columns existed.
 *
 * Order matters here, and getting it wrong fails with:
 *
 *   ERROR 1553 Cannot drop index 'journal_items_account_currency_index':
 *   needed in a foreign key constraint
 *
 * The reason is that this composite index on (account_id, currency) became the
 * *only* index on account_id. The table was originally created with
 * `constrained('accounts')`, and MySQL satisfied that foreign key by reusing the
 * composite index -- its leftmost column is account_id, so it qualifies -- rather
 * than keeping a separate single-column index. Dropping it therefore leaves the
 * foreign key with nothing to hang off, and MySQL refuses.
 *
 * So a plain account_id index is recreated *first*, which restores the foreign
 * key's support. Only then is the composite index safe to drop. Doing it in the
 * other order is the failure this comment exists to prevent.
 *
 * The columns are dropped one statement at a time rather than in a single
 * Schema::table closure, because dropping `currency` implicitly removes the
 * composite index, and doing that while a foreign key still depends on it is the
 * same error 1553 by a different route.
 */
public function down(): void
{
    // Re-establish the foreign key's index before touching the composite one.
    Schema::table('journal_entry_items', function (Blueprint $table) {
        $table->index('account_id', 'journal_entry_items_account_id_index');
    });

    Schema::table('journal_entry_items', function (Blueprint $table) {
        $table->dropIndex('journal_items_account_currency_index');
    });

    // `currency` is part of the composite index, so dropping it must come last:
    // the four columns are removed together and the index goes with the first.
    Schema::table('journal_entry_items', function (Blueprint $table) {
        $table->dropColumn(['currency', 'exchange_rate', 'original_debit', 'original_credit']);
    });
}
};