<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales_payments', 'number')) {
            Schema::table('sales_payments', function (Blueprint $table) {
                $table->string('number')->unique()->nullable()->after('id');
            });
        }

        // Backfill any pre-existing rows with unique numbers before locking the column down.
        $unnumbered = DB::table('sales_payments')->whereNull('number')->get();
        $prefix = 'RC-'.now()->format('Y').'-';
        $pad = (int) DB::table('sales_payments')->whereNotNull('number')->count();
        foreach ($unnumbered as $row) {
            $pad++;
            DB::table('sales_payments')->where('id', $row->id)->update([
                'number' => $prefix.str_pad($pad, 4, '0', STR_PAD_LEFT),
            ]);
        }
    }

    public function down(): void
    {
        try {
            DB::statement('ALTER TABLE sales_payments DROP INDEX sales_payments_number_unique');
        } catch (\Throwable $e) {
        }

        if (Schema::hasColumn('sales_payments', 'number')) {
            Schema::table('sales_payments', function (Blueprint $table) {
                $table->dropColumn('number');
            });
        }
    }
};
