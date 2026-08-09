<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_production_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained('inventory_production_orders')->cascadeOnDelete();
            $table->foreignId('component_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->decimal('quantity_required', 14, 3);
            $table->decimal('quantity_used', 14, 3)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_production_order_items');
    }
};
