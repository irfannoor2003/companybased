<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_delivery_notes', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->foreignId('customer_id')->constrained('sales_customers')->cascadeOnDelete();
            $table->date('issue_date');
            $table->enum('status', ['pending', 'packed', 'shipped', 'delivered'])->default('pending');
            $table->string('shipping_address')->nullable();
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_delivery_notes');
    }
};
