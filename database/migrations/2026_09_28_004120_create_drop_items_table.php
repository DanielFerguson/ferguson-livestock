<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('drop_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('price');
            $table->string('stripe_price_id');
            // Both null for items without their own stock (the 10kg box draws on the 5kg box; delivery has none).
            $table->unsignedInteger('quantity')->nullable();
            $table->unsignedInteger('available')->nullable();
            $table->unsignedSmallInteger('max_per_order')->default(10);
            $table->timestamps();

            $table->unique(['drop_id', 'product_id']);
        });

        // The database itself refuses an oversell (available below zero) or a double release (above quantity).
        DB::statement('ALTER TABLE drop_items ADD CONSTRAINT drop_items_stock_bounds CHECK ((quantity IS NULL AND available IS NULL) OR (available >= 0 AND available <= quantity))');
        DB::statement('ALTER TABLE drop_items ADD CONSTRAINT drop_items_price_positive CHECK (price >= 0)');
        DB::statement('ALTER TABLE drop_items ADD CONSTRAINT drop_items_max_per_order_positive CHECK (max_per_order >= 1)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drop_items');
    }
};
