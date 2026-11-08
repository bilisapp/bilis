<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The state of a rule Bilis set up from its template catalogue
     * (specs/alerting-defaults.md): which template, which version of it, and
     * whether a person has edited it since — an edited rule never receives the
     * catalogue's updates. One rule per template per team; null keys (rules a
     * person wrote) are distinct in a unique index on PostgreSQL and SQLite.
     *
     * `project_ids` becomes nullable: null reads every project of the team,
     * including ones created after the rule.
     */
    public function up(): void
    {
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->json('project_ids')->nullable()->change();
            $table->string('template_key', 64)->nullable()->after('created_by');
            $table->unsignedInteger('template_version')->nullable()->after('template_key');
            $table->timestamp('customized_at')->nullable()->after('template_version');

            $table->unique(['team_id', 'template_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'template_key']);
            $table->dropColumn(['template_key', 'template_version', 'customized_at']);
        });
    }
};
