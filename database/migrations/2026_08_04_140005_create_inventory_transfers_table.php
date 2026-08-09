<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('from_warehouse_id')->constrained('inventory_warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('inventory_warehouses')->restrictOnDelete();
            $table->date('transfer_date');
            $table->string('status')->default('draft');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['from_warehouse_id', 'to_warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfers');
    }
};
