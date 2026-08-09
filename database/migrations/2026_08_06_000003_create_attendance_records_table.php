<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('attendance_date');
            $table->dateTime('check_in_at')->nullable();
            $table->dateTime('check_out_at')->nullable();
            $table->string('method', 20)->default('manual');
            $table->string('status', 20)->nullable();
            $table->unsignedInteger('work_minutes')->nullable();
            $table->string('shift_start', 5)->nullable();
            $table->string('shift_end', 5)->nullable();
            $table->unsignedInteger('grace_minutes')->nullable();
            $table->boolean('is_weekend')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['employee_id', 'attendance_date']);
            $table->index('attendance_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
