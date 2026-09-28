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
        Schema::table('drops', function (Blueprint $table) {
            // Set while a draft drop's date is announced on the website, before its stock is known.
            $table->timestamp('announced_at')->nullable()->after('published_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drops', function (Blueprint $table) {
            $table->dropColumn('announced_at');
        });
    }
};
