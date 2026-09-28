<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Subscribers imported from Klaviyo may not have a first name or postcode.
     */
    public function up(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->string('first_name', 60)->nullable()->change();
            $table->string('postcode', 4)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->string('first_name', 60)->nullable(false)->change();
            $table->string('postcode', 4)->nullable(false)->change();
        });
    }
};
