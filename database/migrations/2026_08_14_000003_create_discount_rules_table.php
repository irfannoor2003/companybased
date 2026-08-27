<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['percentage', 'fixed'])->default('percentage');
            $table->decimal('max_value', 10, 2); // Max percentage (e.g., 25) or max fixed amount
            $table->string('currency', 3)->nullable(); // For fixed type
            $table->json('applicable_to')->nullable(); // ['all'] or ['product_id:1', 'category_id:2']
            $table->json('roles')->nullable(); // ['Salesman'] - roles this rule applies to
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_rules');
    }
};
