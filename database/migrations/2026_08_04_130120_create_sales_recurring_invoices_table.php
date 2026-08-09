<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_recurring_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('sales_customers')->cascadeOnDelete();
            $table->string('name');
            $table->enum('frequency', ['weekly', 'monthly', 'quarterly', 'yearly'])->default('monthly');
            $table->date('next_run_date');
            $table->date('last_run_date')->nullable();
            $table->integer('day_of_cycle')->default(1);
            $table->string('currency')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_recurring_invoices');
    }
};
