<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reconciliation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_transaction_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_cleared')->default(true);
            $table->timestamps();

            $table->unique(['reconciliation_id', 'bank_transaction_id'], 'recon_items_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_items');
    }
};
