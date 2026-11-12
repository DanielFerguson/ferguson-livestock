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
        Schema::table('orders', function (Blueprint $table): void {
            // How the order is paid for: through Stripe, or in cash to the owner (family and friends only).
            $table->string('payment_method', 8)->default('stripe')->after('status');
            // A cash order is recorded by hand, so it has no checkout session and no payment page to time out.
            $table->string('session_fingerprint', 64)->nullable()->change();
            $table->timestamp('expires_at')->nullable()->change();
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_method_known CHECK (payment_method IN ('stripe', 'cash'))");
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_known');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_known CHECK (status IN ('pending', 'processing', 'paid', 'fulfilled', 'expired', 'failed', 'cash_owed'))");
    }

    /**
     * Reverse the migrations. Only works while there are no cash orders: they have no checkout session or expiry to
     * fall back to, and their status isn't one the old constraint knows.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_known');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_known CHECK (status IN ('pending', 'processing', 'paid', 'fulfilled', 'expired', 'failed'))");
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_payment_method_known');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('payment_method');
            $table->string('session_fingerprint', 64)->nullable(false)->change();
            $table->timestamp('expires_at')->nullable(false)->change();
        });
    }
};
