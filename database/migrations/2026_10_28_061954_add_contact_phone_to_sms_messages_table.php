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
        Schema::table('sms_messages', function (Blueprint $table) {
            // The other person's number, whichever way the text went: what groups texts into conversations.
            $table->string('contact_phone', 16)->nullable()->after('subscriber_id');
        });

        DB::statement('update sms_messages set contact_phone = case when direction = \'inbound\' then "from" else "to" end');

        Schema::table('sms_messages', function (Blueprint $table) {
            $table->string('contact_phone', 16)->nullable(false)->change();
            $table->index(['contact_phone', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropIndex(['contact_phone', 'created_at']);
            $table->dropColumn('contact_phone');
        });
    }
};
