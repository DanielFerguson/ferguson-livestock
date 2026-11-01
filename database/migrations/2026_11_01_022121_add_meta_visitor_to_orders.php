<?php

declare(strict_types=1);

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
        Schema::table('orders', function (Blueprint $table): void {
            // The buyer's browser as checkout started (address, browser and Meta's ad-click cookies), so the purchase
            // reported to Meta once Stripe confirms it can be matched to the ad they came from.
            $table->json('meta_visitor')->nullable()->after('via_early_access');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('meta_visitor');
        });
    }
};
