<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            /**
             * The discount off the claimed amount, as a percentage. Mutually
             * exclusive with `discount_amount` -- a line (or here, a claim) is
             * discounted by one style or the other, never both.
             */
            $table->decimal('discount_percent', 5, 2)->default(0)->after('amount');

            /**
             * The discount off the claimed amount, as a specific amount. When set
             * it is the discount; otherwise the percentage is. This is the
             * "the other party gave the employee X off the bill" figure.
             */
            $table->decimal('discount_amount', 14, 2)->default(0)->after('discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('expense_claims', function (Blueprint $table) {
            $table->dropColumn('discount_amount');
            $table->dropColumn('discount_percent');
        });
    }
};
