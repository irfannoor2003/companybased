<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time booked to a production order.
 *
 * Absorption needs labour traced to the job that consumed it. Nothing linked the
 * two: attendance recorded minutes against an employee, production recorded
 * quantities against an order, and there was no path from one to the other. So
 * factory labour could only ever be expensed in total, never capitalised into the
 * goods it made -- which understates inventory and overstates the cost of every
 * manufactured item.
 *
 * The hourly rate is stored on the row rather than read from the employee's
 * current salary at posting time. A pay rise must not silently restate the cost of
 * work already done and already posted; the rate that applied when the hours were
 * worked is the one that belongs in the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('production_labour_entries')) {
            return;
        }

        Schema::create('production_labour_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_order_id')->constrained('inventory_production_orders')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();

            // Minutes rather than hours, because that is what attendance records.
            // Rounding to whole hours would discard up to 59 minutes of real work
            // per entry.
            $table->unsignedInteger('minutes');

            // Rate snapshot. See above: this must not follow a later salary change.
            $table->decimal('hourly_rate', 14, 4)->default(0);
            $table->decimal('labour_cost', 14, 2)->default(0);

            $table->date('work_date');

            // Set once the cost has been moved into work in progress. Null means
            // still sitting in period expense, which is what makes absorption
            // repeatable without double-posting.
            $table->timestamp('absorbed_at')->nullable();

            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['production_order_id', 'absorbed_at']);
            $table->index(['employee_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_labour_entries');
    }
};