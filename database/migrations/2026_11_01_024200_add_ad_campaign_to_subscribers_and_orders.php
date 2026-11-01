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
        // The UTM tags on the ad link that brought someone to the site, so sales can be traced back to Meta ads long
        // after Meta stops crediting them (7 days after the click). Null when they didn't come from a tagged link.
        foreach (['subscribers', 'orders'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->string('utm_source', 100)->nullable()->index();
                $table->string('utm_medium', 100)->nullable();
                $table->string('utm_campaign', 150)->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['subscribers', 'orders'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropIndex(['utm_source']);
                $table->dropColumn(['utm_source', 'utm_medium', 'utm_campaign']);
            });
        }
    }
};
