<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->foreignId('customer_id')->constrained('sales_customers')->cascadeOnDelete();
            $table->date('issue_date');
            $table->string('reason')->nullable();
            $table->string('currency')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('applied_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'issue_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_credit_notes');
    }
};
