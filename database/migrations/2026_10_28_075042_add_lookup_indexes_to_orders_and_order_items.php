<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Postgres doesn't index foreign keys on its own. Orders are looked up by drop (the dashboard, the delivery
     * run, stock counts) and items by order (every confirmation, email and sweep).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index('drop_id');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['drop_id']);
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex(['order_id']);
        });
    }
};
