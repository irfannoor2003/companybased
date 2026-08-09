<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capital_drawings', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->date('drawing_date');
            $table->string('recipient');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 8)->nullable();
            $table->string('method', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('recipient');
            $table->index('drawing_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capital_drawings');
    }
};