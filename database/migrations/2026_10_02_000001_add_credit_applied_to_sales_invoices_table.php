<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer credit consumed by an invoice.
 *
 * A customer who overpays holds money with the company. That surplus used to be
 * impossible to create — the payment guard refused any amount above the
 * outstanding balance outright — so a customer could never carry credit earned
 * by overpaying. It could only be faked with a negative opening balance, which
 * is a different thing and never showed up as "this invoice was settled from
 * credit".
 *
 * This column records how much of a given invoice was settled from the
 * customer's balance rather than by a cash receipt, so the invoice carries the
 * fact with it. It is kept apart from paid_amount rather than folded into it:
 * paid_amount is the sum of receipts against the invoice and is recomputed from
 * the payment rows, whereas credit_applied is money that arrived on an earlier
 * document. Collapsing the two would make an invoice look cash-settled and
 * would silently rewrite history every time a payment was added or removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_invoices')) {
            return;
        }

        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->decimal('credit_applied', 14, 2)->default(0)->after('paid_amount');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales_invoices')) {
            return;
        }

        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->dropColumn('credit_applied');
        });
    }
};