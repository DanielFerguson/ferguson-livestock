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
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            // Given away rather than sold, like a sample pack handed over at pickup. It still takes stock.
            $table->boolean('free')->default(false)->after('unit_price');
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_free_costs_nothing CHECK (NOT free OR unit_price = 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT order_items_free_costs_nothing');

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('free');
        });
    }
};
