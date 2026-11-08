<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Prometheus' "active alerts": one row per series a rule has produced,
     * keyed by the hash of its labels. `keep_firing_since` is Prometheus'
     * KeepFiringSince — when a firing instance's condition first turned false.
     */
    public function up(): void
    {
        Schema::create('alert_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->json('labels');
            $table->string('labels_hash', 40);
            $table->string('state', 16)->default('inactive');
            $table->double('value')->nullable();
            $table->timestamp('active_at')->nullable();
            $table->timestamp('fired_at')->nullable();
            $table->timestamp('keep_firing_since')->nullable();
            $table->timestamp('last_seen_with_data_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['alert_rule_id', 'labels_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_instances');
    }
};
