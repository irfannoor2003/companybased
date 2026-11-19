<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-role and per-user sidebar choices.
 *
 * One row per (scope, nav key). A row records whether that link is visible, or
 * describes a custom link with a label, URL and icon. The same table serves both
 * scopes: role_id rows are the defaults, user_id rows override them per key.
 * Nothing here grants access — NavBuilder still applies module + permission
 * checks after reading these rows, so a preference can only hide a link the
 * user could already see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sidebar_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('nav_key', 180);
            $table->string('label')->nullable();
            $table->string('url')->nullable();
            $table->string('icon', 50)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'nav_key']);
            $table->unique(['role_id', 'nav_key']);
            $table->index(['user_id', 'sort_order']);
            $table->index(['role_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sidebar_preferences');
    }
};
