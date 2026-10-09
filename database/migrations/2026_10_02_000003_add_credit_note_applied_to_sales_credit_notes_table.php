<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of each credit note its own invoice absorbed.
 *
 * `applied_amount` on the note is the value credited to the customer account —
 * the full note — because that is what SalesCustomer::balance() subtracts. This
 * column records the smaller, different question of how much of that value
 * settled the specific invoice the note was raised against, which is what the
 * invoice's own `credit_note_applied` is the sum of.
 *
 * Keeping them apart is what stops the two from contradicting each other: a 900
 * note against a 300 invoice credits the customer 900 and settles 300 of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_credit_notes')) {
            return;
        }

        Schema::table('sales_credit_notes', function (Blueprint $table): void {
            $table->decimal('credit_note_applied', 14, 2)->default(0)->after('applied_amount');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales_credit_notes')) {
            return;
        }

        Schema::table('sales_credit_notes', function (Blueprint $table): void {
            $table->dropColumn('credit_note_applied');
        });
    }
};