<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credit-note credit consumed by an invoice.
 *
 * A credit note had no financial effect anywhere in the system. `applied_amount`
 * on the note was in $fillable and read by SalesCustomer::balance(), the
 * statements and the supplier ledger — but no code path ever wrote it, so it sat
 * at its default of zero. Issuing a 500.00 credit note against a 2,000.00
 * invoice left the balance at 2,000.00 and printed a 0.00 credit line.
 *
 * This column is the other half of that: without somewhere on the invoice to
 * record the credit that has been used against it, a note could reduce the
 * customer balance and the invoice would still read as fully owed. The two have
 * to move together — the note's `applied_amount` is what the customer ledger
 * counts, and this is the same figure seen from the invoice's side.
 *
 * Kept separate from `paid_amount` (cash receipts) and `credit_applied` (credit
 * drawn from the customer's forward balance) because the three are different
 * mechanisms and only the first is recomputed from the payment rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_invoices')) {
            return;
        }

        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->decimal('credit_note_applied', 14, 2)->default(0)->after('credit_applied');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales_invoices')) {
            return;
        }

        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->dropColumn('credit_note_applied');
        });
    }
};