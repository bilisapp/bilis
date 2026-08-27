<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Disconnecting a repository must not take the fix jobs raised against it
     * with it: `fix_jobs.project_repository_id` cascades on delete, and those
     * rows are the record of what was attempted plus the cooldown state that
     * keeps the scan from re-raising the same error. So a disconnect soft
     * deletes instead, and the cascade is left for the one case it is right
     * for — deleting the project itself.
     */
    public function up(): void
    {
        Schema::table('project_repositories', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_repositories', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
