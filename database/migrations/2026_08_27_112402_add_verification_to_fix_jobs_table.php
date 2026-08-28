<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The verification loop reads a merged job's fingerprint back out of the
     * logs once the fix has had time to deploy. `verification` records what it
     * found — and, once written, marks the job as handled so the loop never
     * comments on the same pull request twice. `verified_at` is set only when
     * the error actually stopped happening.
     */
    public function up(): void
    {
        Schema::table('fix_jobs', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable()->after('completed_at');
            $table->json('verification')->nullable()->after('events');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fix_jobs', function (Blueprint $table) {
            $table->dropColumn(['verified_at', 'verification']);
        });
    }
};
