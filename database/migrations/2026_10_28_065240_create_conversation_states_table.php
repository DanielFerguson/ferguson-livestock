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
        Schema::create('conversation_states', function (Blueprint $table) {
            $table->id();
            // The other person's number, as on sms_messages: people who aren't subscribers have conversations too.
            $table->string('contact_phone', 16)->unique();
            // Flagged to come back to: stays flagged until it's ticked off as done.
            $table->timestamp('follow_up_at')->nullable();
            // Ticked off as done. A text from them after this time reopens the conversation.
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversation_states');
    }
};
