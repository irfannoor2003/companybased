<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_code')->unique();
            $table->string('name');
            $table->string('category')->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_cost', 14, 2)->default(0);
            $table->decimal('salvage_value', 14, 2)->default(0);
            $table->unsignedInteger('useful_life_months')->default(0);
            $table->string('depreciation_method', 30)->default('straight_line');
            $table->decimal('depreciation_rate', 8, 4)->nullable();
            $table->string('location')->nullable();
            $table->string('department')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('supplier')->nullable();
            $table->string('status', 20)->default('in_use');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('category');
            $table->index('status');
            $table->index('purchase_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
    }
};