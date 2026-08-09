<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sales', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number')->unique();
            $table->foreignId('shift_id')->constrained('pos_shifts')->cascadeOnDelete();
            $table->string('customer_name')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->foreignId('payment_method_id')->nullable()->constrained('pos_payment_methods')->nullOnDelete();
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->decimal('change_due', 14, 2)->default(0);
            $table->string('status', 20)->default('completed');
            $table->timestamp('sold_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('sold_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sales');
    }
};