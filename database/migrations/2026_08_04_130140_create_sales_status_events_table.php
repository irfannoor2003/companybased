<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_status_events', function (Blueprint $table) {
            $table->id();
            $table->string('trackable_type');
            $table->unsignedBigInteger('trackable_id');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['trackable_type', 'trackable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_status_events');
    }
};
