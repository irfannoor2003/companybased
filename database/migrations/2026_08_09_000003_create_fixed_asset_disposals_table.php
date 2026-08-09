<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_disposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            $table->date('disposal_date');
            $table->string('method', 30)->default('sold');
            $table->decimal('proceeds', 14, 2)->default(0);
            $table->decimal('book_value', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('disposal_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_disposals');
    }
};