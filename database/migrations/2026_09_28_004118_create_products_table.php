<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            // Stable identifiers such as `beef-box-5kg`, kept from the Astro site.
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description');
            $table->enum('type', ['box', 'extra', 'delivery']);
            // Products that draw on another product's stock, e.g. the 10kg box uses two 5kg-box units.
            $table->foreignId('stock_product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->unsignedSmallInteger('stock_units')->default(1);
            $table->jsonb('box_details')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
