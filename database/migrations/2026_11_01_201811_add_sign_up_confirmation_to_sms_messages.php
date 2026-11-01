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
        Schema::table('sms_messages', function (Blueprint $table): void {
            $table->boolean('is_sign_up_confirmation')->default(false);
        });

        // Confirmations sent before this column existed, so nobody is offered a second one.
        DB::table('sms_messages')
            ->where('direction', 'outbound')
            ->whereNull('sms_broadcast_id')
            ->where('body', "Ferguson Livestock: You're on the wait list. We'll text you from this number when the next drop opens.\nReply STOP to opt out")
            ->update(['is_sign_up_confirmation' => true]);

        // A subscriber can only ever have one.
        DB::statement('CREATE UNIQUE INDEX sms_messages_one_sign_up_confirmation ON sms_messages (subscriber_id) WHERE is_sign_up_confirmation');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $table): void {
            $table->dropColumn('is_sign_up_confirmation');
        });
    }
};
