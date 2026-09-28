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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            // Shown to customers and sent to Stripe, so order numbers can't be guessed.
            $table->ulid('public_id')->unique();
            $table->foreignId('drop_id')->constrained()->restrictOnDelete();
            $table->string('status', 12);
            $table->string('delivery_method', 8);
            $table->date('delivery_day')->nullable();
            // Filled in from Stripe once the customer has paid.
            $table->string('customer_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->jsonb('shipping_address')->nullable();
            $table->unsignedInteger('total');
            $table->unsignedInteger('amount_refunded')->default(0);
            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable()->index();
            // A hash of the customer's session, to find an earlier unpaid order of theirs.
            $table->string('session_fingerprint', 64)->index();
            $table->timestamp('expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_known CHECK (status IN ('pending', 'processing', 'paid', 'fulfilled', 'expired', 'failed'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_delivery_method_known CHECK (delivery_method IN ('delivery', 'pickup'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_refund_within_total CHECK (amount_refunded <= total)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
