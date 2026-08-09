<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employee_code')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->date('date_hired');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('job_title')->nullable();
            $table->string('employment_status', 20)->default('active');
            $table->text('address')->nullable();
            $table->boolean('attendance_enabled')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('department_id');
            $table->index('user_id');
            $table->index('employment_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
