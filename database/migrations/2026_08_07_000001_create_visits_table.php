<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->string('visit_number')->unique();
            $table->foreignId('customer_id')->nullable()->constrained('sales_customers')->nullOnDelete();
            $table->foreignId('sales_rep_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('purpose')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('pending');
            $table->date('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->decimal('start_lat', 10, 7)->nullable();
            $table->decimal('start_lng', 10, 7)->nullable();
            $table->string('outcome', 40)->nullable();
            $table->text('outcome_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('customer_id');
            $table->index('sales_rep_id');
            $table->index('status');
            $table->index('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
