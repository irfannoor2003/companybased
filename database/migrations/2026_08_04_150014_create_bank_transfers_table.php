<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->date('transfer_date');
            $table->foreignId('from_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transfers');
    }
};
