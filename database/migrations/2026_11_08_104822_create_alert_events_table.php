<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Append-only alert history. The unique `dedupe_key` is what makes a
     * retried evaluation job write — and notify — nothing twice. Rule and
     * instance links are nulled, not cascaded, so history outlives both.
     */
    public function up(): void
    {
        Schema::create('alert_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('alert_instance_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->json('labels');
            $table->double('value')->nullable();
            $table->double('threshold')->nullable();
            $table->timestamp('occurred_at');
            $table->json('context')->nullable();
            $table->string('dedupe_key');
            $table->timestamp('created_at')->nullable();

            $table->unique('dedupe_key');
            $table->index(['team_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_events');
    }
};
