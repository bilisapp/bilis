<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The rule's comparison and severity as they were when the event was
     * recorded, beside the `threshold` already frozen there, so history and a
     * notification still waiting in the queue never describe a past event
     * with a condition edited since. Null for issue events and for rows
     * written before this column existed (those fall back to the rule).
     */
    public function up(): void
    {
        Schema::table('alert_events', function (Blueprint $table) {
            $table->string('condition', 2)->nullable()->after('threshold');
            $table->string('severity', 16)->nullable()->after('condition');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alert_events', function (Blueprint $table) {
            $table->dropColumn(['condition', 'severity']);
        });
    }
};
