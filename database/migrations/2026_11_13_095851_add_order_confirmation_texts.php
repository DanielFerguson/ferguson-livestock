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
        Schema::table('sms_messages', function (Blueprint $table): void {
            // A text telling a customer what they ordered and when it's ready, rather than a broadcast or a reply.
            $table->boolean('is_order_confirmation')->default(false)->after('is_sign_up_confirmation');
        });

        Schema::table('orders', function (Blueprint $table): void {
            // The text that tells the customer about this order. Set when the text is saved, waiting or sent, so an
            // order is only ever texted about once.
            $table->foreignId('confirmation_sms_message_id')->nullable()->after('paid_at')->constrained('sms_messages')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('confirmation_sms_message_id');
        });

        Schema::table('sms_messages', function (Blueprint $table): void {
            $table->dropColumn('is_order_confirmation');
        });
    }
};
