<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give debit notes the same per-document exchange-rate snapshot that every
     * other currency-bearing document already has (see
     * 2026_08_14_000002_add_exchange_rate_to_document_tables). Without it the
     * supplier ledger could not convert the note at its own locked-in rate.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('debit_notes', 'exchange_rate')) {
            Schema::table('debit_notes', function (Blueprint $table) {
                $table->decimal('exchange_rate', 18, 6)->nullable()->default(1.000000);
            });
        }

        $base = base_currency();

        foreach (DB::table('debit_notes')->get() as $note) {
            $currency = strtoupper((string) ($note->currency
                ?: DB::table('suppliers')->where('id', $note->supplier_id)->value('currency')
                ?: $base));

            if ($currency === $base) {
                DB::table('debit_notes')->where('id', $note->id)->update(['exchange_rate' => 1.000000]);
                continue;
            }

            $rate = DB::table('exchange_rates')
                ->where('currency_code', $currency)
                ->where('effective_date', '<=', $note->issue_date ?? now()->toDateString())
                ->orderByDesc('effective_date')
                ->orderByDesc('id')
                ->value('rate_to_base');

            DB::table('debit_notes')->where('id', $note->id)->update(['exchange_rate' => $rate ?? 1.000000]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('debit_notes', 'exchange_rate')) {
            Schema::table('debit_notes', function (Blueprint $table) {
                $table->dropColumn('exchange_rate');
            });
        }
    }
};
