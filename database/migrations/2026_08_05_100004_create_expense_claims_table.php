<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_claims', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->string('employee_name');
            $table->date('expense_date');
            $table->string('expense_type', 40)->default('other');
            $table->string('merchant')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 8)->default('USD');
            $table->string('status', 20)->default('pending');
            $table->text('notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('reimbursed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_claims');
    }
};