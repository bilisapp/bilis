<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A fix job no longer has to come from an error. `type` defaults to
     * `error` so every row already in the table keeps its meaning, and the two
     * columns that only an error job can fill become nullable.
     */
    public function up(): void
    {
        Schema::table('fix_jobs', function (Blueprint $table) {
            $table->string('type')->default('error')->index();
            $table->text('instructions')->nullable();
        });

        Schema::table('fix_jobs', function (Blueprint $table) {
            $table->string('fingerprint')->nullable()->change();
            $table->json('error_context')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fix_jobs', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'instructions']);
        });
    }
};
