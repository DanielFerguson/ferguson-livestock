<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A bundle is a product sold as several units of another product, like the 10kg beef box (two 5kg boxes) or
     * a 5kg mince pack (ten 500g packs). It replaces products.stock_product_id and stock_units, which stay in
     * place, unused, until a later migration drops them, so a rollback or a rolling deploy never breaks.
     */
    public function up(): void
    {
        Schema::create('bundles', function (Blueprint $table): void {
            $table->id();
            // The product customers buy. One bundle per product, and deleting the product deletes its bundle.
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            // The product whose stock it uses up.
            $table->foreignId('component_product_id')->constrained('products')->restrictOnDelete();
            // How many of the component each one uses.
            $table->unsignedSmallInteger('units');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE bundles ADD CONSTRAINT bundles_units_positive CHECK (units >= 1)');
        DB::statement('ALTER TABLE bundles ADD CONSTRAINT bundles_not_its_own_component CHECK (product_id <> component_product_id)');

        DB::statement('
            INSERT INTO bundles (product_id, component_product_id, units, created_at, updated_at)
            SELECT id, stock_product_id, GREATEST(stock_units, 1), NOW(), NOW()
            FROM products
            WHERE stock_product_id IS NOT NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bundles');
    }
};
