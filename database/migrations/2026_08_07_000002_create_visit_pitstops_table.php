<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_pitstops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('sales_customers')->nullOnDelete();
            $table->string('purpose')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->string('image_path')->nullable();
            $table->timestamp('visited_at');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('visit_id');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_pitstops');
    }
};