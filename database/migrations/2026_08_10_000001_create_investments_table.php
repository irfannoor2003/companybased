<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investments', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type', 40)->default('stock');
            $table->string('institution')->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('quantity', 18, 6)->default(0);
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->decimal('current_price', 14, 2)->nullable();
            $table->decimal('current_value', 14, 2)->nullable();
            $table->string('currency', 8)->nullable();
            $table->date('maturity_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investments');
    }
};