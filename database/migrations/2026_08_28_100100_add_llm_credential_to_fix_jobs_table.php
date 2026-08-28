<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Which credential this job runs on, chosen when the job is raised rather
     * than read at dispatch. A person picks it in the new-job dialog; the
     * scheduled scan takes the team's default. Pinning it at creation is what
     * makes a job answerable afterwards: "which key paid for this" has one
     * answer, and it does not change because somebody edited team settings
     * while the run was in flight.
     *
     * Nullable, and `nullOnDelete`: deleting a credential must not delete the
     * history of what it ran, and an in-flight job whose credential is removed
     * falls back to the team's default at dispatch rather than dying.
     */
    public function up(): void
    {
        Schema::table('fix_jobs', function (Blueprint $table) {
            $table->foreignId('team_llm_credential_id')
                ->nullable()
                ->after('project_repository_id')
                ->constrained('team_llm_credentials')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fix_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_llm_credential_id');
        });
    }
};
