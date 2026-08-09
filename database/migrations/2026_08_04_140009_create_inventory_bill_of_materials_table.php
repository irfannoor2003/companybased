<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_bill_of_materials', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->string('version')->default('1');
            $table->string('status')->default('active');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_bill_of_materials');
    }
};
