<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained('pos_shifts')->cascadeOnDelete();
            $table->foreignId('reconciled_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reconciled_at');
            $table->decimal('opening_cash', 14, 2)->default(0);
            $table->decimal('sales_total', 14, 2)->default(0);
            $table->decimal('expected_cash', 14, 2)->default(0);
            $table->decimal('counted_cash', 14, 2)->default(0);
            $table->decimal('variance', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_reconciliations');
    }
};