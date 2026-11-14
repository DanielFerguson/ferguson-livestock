<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // The secret in the link to the customer's order page. Not public_id: Meta and Stripe see that.
            $table->string('access_token', 16)->nullable();
        });

        // A farm's worth of orders, so one update each is fine.
        foreach (DB::table('orders')->pluck('id') as $id) {
            DB::table('orders')->where('id', $id)->update(['access_token' => Str::random(16)]);
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('access_token', 16)->nullable(false)->change();
            $table->unique('access_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['access_token']);
            $table->dropColumn('access_token');
        });
    }
};
