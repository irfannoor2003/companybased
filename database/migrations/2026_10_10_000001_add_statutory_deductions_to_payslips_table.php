<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record statutory deductions separately from attendance deductions.
 *
 * `total_deductions` is the sum of everything taken out of a payslip. Until this
 * column existed, the only thing in that sum was attendance — an absent day or a
 * late arrival — and the controller derived "withheld tax" as gross minus net.
 *
 * That derivation is the bug this migration exists to remove. An attendance
 * deduction was never tax: the employee simply did not earn that portion, and the
 * employer never incurred a liability to any authority for it. Posting the whole
 * difference as tax to Payroll Tax Payable made the company owe money to the tax
 * authority that it did not owe, and left the real wage cost overstated, because
 * attendance came back out of the expense rather than being recognised as a
 * smaller amount earned.
 *
 * Separate columns also make the payslip honest. A reader can see what was lost
 * for lateness and what was withheld for the revenue department, which a single
 * combined figure cannot show.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            /**
             * Income tax, social security and any other withholding remitted to a
             * third party.
             *
             * Zero by default rather than derived, so an operator who has not
             * configured a rate produces a payslip with no withholding -- an
             * explicit "none" -- instead of one that silently claims a liability.
             */
            $table->decimal('statutory_deductions', 14, 2)->default(0)->after('attendance_deductions');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn('statutory_deductions');
        });
    }
};
