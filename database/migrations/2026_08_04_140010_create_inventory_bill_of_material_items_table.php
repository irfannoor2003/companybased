<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_bill_of_material_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_of_material_id')->constrained('inventory_bill_of_materials')->cascadeOnDelete();
            $table->foreignId('component_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('wastage_percent', 5, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_bill_of_material_items');
    }
};
