<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('open');

            /**
             * Set independently of status: the period can still be open for new
             * work while refusing backdated entries. See the model.
             */
            $table->date('date_lock')->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('lock_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['start_date', 'end_date']);
            $table->index('status');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('fiscal_period_id')
                ->nullable()
                ->after('entry_date')
                ->constrained('fiscal_periods')
                ->nullOnDelete();

            // Resolving a period on every lookup is the hot path once periods exist.
            $table->index(['entry_date', 'fiscal_period_id'], 'journal_entries_date_period_index');
        });

        /**
         * A duplicated exact range is refused by the database. Genuine overlap
         * (two partially overlapping ranges) is enforced in FiscalPeriodService,
         * which can report which period conflicts -- a unique index cannot.
         */
        Schema::table('fiscal_periods', function (Blueprint $table) {
            $table->unique(['start_date', 'end_date'], 'fiscal_periods_range_unique');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex('journal_entries_date_period_index');
            $table->dropConstrainedForeignId('fiscal_period_id');
        });

        Schema::table('fiscal_periods', function (Blueprint $table) {
            $table->dropUnique('fiscal_periods_range_unique');
        });

        Schema::dropIfExists('fiscal_periods');
    }
};
