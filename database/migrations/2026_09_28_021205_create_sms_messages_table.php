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
        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            // Null for replies from numbers that aren't subscribers.
            $table->foreignId('subscriber_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sms_broadcast_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('direction', 8);
            $table->string('to', 16);
            $table->string('from', 16);
            $table->text('body');
            $table->string('status', 12);
            $table->string('twilio_sid', 34)->nullable()->unique();
            $table->unsignedSmallInteger('segments')->nullable();
            $table->unsignedInteger('error_code')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // A retried broadcast can never text anyone twice.
            $table->unique(['sms_broadcast_id', 'subscriber_id']);
            $table->index(['sms_broadcast_id', 'status']);
            $table->index(['direction', 'read_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
    }
};
