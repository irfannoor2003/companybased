<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_dividends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investment_id')->constrained('investments')->cascadeOnDelete();
            $table->date('dividend_date');
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('currency', 8)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('dividend_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_dividends');
    }
};