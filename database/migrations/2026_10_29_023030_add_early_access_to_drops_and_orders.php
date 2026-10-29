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
        Schema::table('drops', function (Blueprint $table): void {
            // The secret in a drop's early-access link. Null while early access is off; clearing or replacing it revokes the old link.
            $table->string('early_access_token', 64)->nullable()->unique()->after('announced_at');
        });

        Schema::table('orders', function (Blueprint $table): void {
            // Placed through the early-access link, so a cancelled checkout can go back to it.
            $table->boolean('via_early_access')->default(false)->after('session_fingerprint');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('via_early_access');
        });

        Schema::table('drops', function (Blueprint $table): void {
            $table->dropUnique(['early_access_token']);
            $table->dropColumn('early_access_token');
        });
    }
};
