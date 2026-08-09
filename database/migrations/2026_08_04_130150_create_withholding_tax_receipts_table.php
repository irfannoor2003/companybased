<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withholding_tax_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('customer_id')->constrained('sales_customers')->cascadeOnDelete();
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->date('receipt_date');
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('tax_rate_percent', 5, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->string('currency')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'receipt_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withholding_tax_receipts');
    }
};
