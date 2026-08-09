<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_production_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('bill_of_material_id')->nullable()->constrained('inventory_bill_of_materials')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('inventory_warehouses')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->date('scheduled_start_date')->nullable();
            $table->date('scheduled_end_date')->nullable();
            $table->string('status')->default('draft');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['warehouse_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_production_orders');
    }
};
