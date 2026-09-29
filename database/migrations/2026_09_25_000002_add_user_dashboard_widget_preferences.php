<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashboard_widget_preferences', function (Blueprint $table): void {
            $table->foreignId('role_id')->nullable()->change();
            $table->foreignId('user_id')->nullable()->after('role_id')->constrained()->cascadeOnDelete();
            $table->unique(['user_id', 'widget_key']);
        });
    }

    public function down(): void
    {
        Schema::table('dashboard_widget_preferences', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'widget_key']);
            $table->dropConstrainedForeignId('user_id');
            $table->foreignId('role_id')->nullable(false)->change();
        });
    }
};
