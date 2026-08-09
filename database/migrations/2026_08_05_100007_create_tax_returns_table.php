<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->string('tax_type', 20)->default('sales');
            $table->string('period_label');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('gross_receipts', 14, 2)->default(0);
            $table->decimal('taxable_amount', 14, 2)->default(0);
            $table->decimal('tax_collected', 14, 2)->default(0);
            $table->decimal('tax_credits', 14, 2)->default(0);
            $table->decimal('tax_due', 14, 2)->default(0);
            $table->string('status', 20)->default('draft');
            $table->date('filed_at')->nullable();
            $table->date('paid_at')->nullable();
            $table->string('currency', 8)->default('USD');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_returns');
    }
};