<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer migrating from spreadsheets or another system already has a
 * running balance for that account, and without somewhere to record it the
 * statement can only ever start from zero — which makes every figure on the
 * statement wrong by that opening amount.
 *
 * Stored signed: a positive value means the customer already owed us that much
 * on day one, a negative value means they were in credit with us (an advance or
 * an overpayment we still hold). A single signed column avoids the ambiguity of
 * a separate amount + direction pair that can be persisted inconsistently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_customers', function (Blueprint $table) {
            $table->decimal('opening_balance', 14, 2)->default(0)->after('credit_limit');
            $table->date('opening_balance_date')->nullable()->after('opening_balance');
        });
    }

    public function down(): void
    {
        Schema::table('sales_customers', function (Blueprint $table) {
            $table->dropColumn(['opening_balance', 'opening_balance_date']);
        });
    }
};
