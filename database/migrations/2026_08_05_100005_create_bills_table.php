<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->string('vendor_name');
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->date('bill_date');
            $table->date('due_date')->nullable();
            $table->decimal('amount', 14, 2);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->string('currency', 8)->default('USD');
            $table->string('status', 20)->default('open');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};