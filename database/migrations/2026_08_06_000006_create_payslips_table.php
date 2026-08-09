<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('basic_salary', 14, 2)->default(0);
            $table->decimal('allowances', 14, 2)->default(0);
            $table->decimal('gross_pay', 14, 2)->default(0);
            $table->unsignedInteger('days_present')->default(0);
            $table->unsignedInteger('days_late')->default(0);
            $table->unsignedInteger('days_short_leave')->default(0);
            $table->unsignedInteger('days_half_day')->default(0);
            $table->unsignedInteger('days_absent')->default(0);
            $table->decimal('attendance_deductions', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->decimal('net_pay', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['payroll_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
    }
};
