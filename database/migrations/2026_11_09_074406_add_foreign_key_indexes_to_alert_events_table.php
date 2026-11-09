<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Index the two nullable foreign keys. Neither PostgreSQL nor SQLite
     * indexes a referencing column on its own, so every deleted rule or
     * instance (`nullOnDelete`) scanned the whole history table — and stale
     * instances are cleaned up every minute per rule. The rule index leads a
     * pair with `occurred_at`, which also serves a rule's own history.
     */
    public function up(): void
    {
        Schema::table('alert_events', function (Blueprint $table) {
            $table->index(['alert_rule_id', 'occurred_at']);
            $table->index('alert_instance_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alert_events', function (Blueprint $table) {
            $table->dropIndex(['alert_rule_id', 'occurred_at']);
            $table->dropIndex(['alert_instance_id']);
        });
    }
};
