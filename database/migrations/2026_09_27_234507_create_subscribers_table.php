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
        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 60);
            // E.164, e.g. +61412345678. One entry per mobile number.
            $table->string('phone', 16)->unique();
            $table->string('postcode', 4);
            // Evidence of consent for commercial SMS (Spam Act 2003): when, where, what they agreed to, and from where.
            $table->timestamp('consented_at');
            $table->string('consent_source');
            $table->text('consent_wording');
            $table->string('consent_ip', 45)->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscribers');
    }
};
