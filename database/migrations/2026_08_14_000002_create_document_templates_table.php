<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('type', ['invoice', 'quote', 'order', 'delivery_note', 'credit_note', 'purchase_order', 'purchase_invoice', 'receipt'])->default('invoice');
            $table->text('description')->nullable();
            $table->json('colors')->nullable(); // {'primary': '#4f46e5', 'accent': '#0ea5e9', 'text': '#1f2937'}
            $table->json('layout')->nullable(); // {'header': 'left', 'show_logo': true, 'show_tax': true}
            $table->text('header_html')->nullable(); // Custom header HTML
            $table->text('footer_html')->nullable(); // Custom footer HTML
            $table->text('css')->nullable(); // Custom CSS overrides
            $table->boolean('is_default')->default(false);
            $table->boolean('is_system')->default(false); // System templates can't be deleted
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
