<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investment_id')->constrained('investments')->cascadeOnDelete();
            $table->date('transaction_date');
            $table->string('type', 20)->default('buy');
            $table->decimal('quantity', 18, 6)->default(0);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('fees', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('transaction_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_transactions');
    }
};