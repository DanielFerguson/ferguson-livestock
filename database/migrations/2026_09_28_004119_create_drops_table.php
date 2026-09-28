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
        Schema::create('drops', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // All times are UTC; the admin enters and reads them in Melbourne time.
            $table->timestamp('opens_at')->index();
            $table->timestamp('closes_at')->nullable();
            // Set by "Close now".
            $table->timestamp('closed_at')->nullable();
            // Null while the drop is a draft.
            $table->timestamp('published_at')->nullable();
            // Dates (Y-m-d) customers can choose for delivery.
            $table->jsonb('delivery_days')->default('[]');
            $table->timestamp('preflight_ran_at')->nullable();
            $table->jsonb('preflight_report')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE drops ADD CONSTRAINT drops_closes_after_opening CHECK (closes_at IS NULL OR closes_at > opens_at)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drops');
    }
};
