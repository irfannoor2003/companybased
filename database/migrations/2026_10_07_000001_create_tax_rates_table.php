<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A catalogue of tax rates.
 *
 * The application already had a `tax_rates.view` / `tax_rates.manage` permission
 * and nothing behind it. Every line on every document carried a hand-typed
 * `tax_percent`, so the rate was re-entered by hand on every invoice, nothing
 * validated it, and changing the rate meant editing documents already issued --
 * which is how a company ends up charging two different rates for the same goods
 * in the same month.
 *
 * Two decisions worth stating, because both are how real tax tables work and
 * neither is obvious:
 *
 *  - Rates are EFFECTIVE DATED. A rate has an effective_from and optionally an
 *    effective_to. Changing the rate creates a new row rather than editing the
 *    old one, so a document issued last month still resolves to last month's
 *    rate. Editing in place would silently restate history.
 *
 *  - The ledger account is stored on the rate. Which account output tax lands in
 *    is a property of the rate, not of the invoice, so it belongs here rather
 *    than being hard-coded in the posting rules.
 *
 * `priority` exists because overlapping effective ranges are legitimate: a
 * standard rate and a reduced rate can both apply on the same day, and the
 * more specific one is given a higher number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();

            $table->string('code', 40)->unique();
            $table->string('name');

            /**
             * Percentage, stored to 4 places.
             *
             * Two places is not enough: a rate such as 16.6667% would round to
             * 16.67 and put the collected tax permanently out of step with the
             * declared rate.
             */
            $table->decimal('rate', 8, 4)->default(0);

            // 'sales' charges the customer, 'purchase' is reclaimable input tax.
            $table->string('applies_to', 20)->default('sales');

            // Where the collected tax is posted. Null only where the rate is
            // informational and nothing is posted.
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            /**
             * Higher wins when two rates are effective on the same day, which is
             * how a reduced or special rate takes precedence over the standard one
             * without either having to be disabled.
             */
            $table->unsignedInteger('priority')->default(0);

            /**
             * Tax charged on the tax as well as the net.
             *
             * Included because some regimes require it and getting it wrong in
             * either direction misstates every invoice it touches. For the common
             * non-compounding case this stays off.
             */
            $table->boolean('is_compound')->default(false);

            /**
             * Only applies above this net amount.
             *
             * Threshold regimes exempt small transactions. Null means no
             * threshold. Stored as a money amount in the rate's own currency.
             */
            $table->decimal('threshold_amount', 14, 2)->nullable();

            // A rate that is genuinely exempt rather than merely inactive.
            $table->boolean('is_exempt')->default(false);

            $table->boolean('is_active')->default(true);

            $table->string('currency', 8)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            /**
             * The lookup is "which rates were in force on date X for sales?",
             * ordered by date and priority. This is the index for it.
             */
            $table->index(['applies_to', 'effective_from', 'priority'], 'tax_rates_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};