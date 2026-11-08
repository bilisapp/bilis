<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per alert rule: a structured query against one source, a
     * condition, and Prometheus' `for` / `keep_firing_for`. `next_evaluation_at`
     * is the scheduler's queue, hence the (enabled, next_evaluation_at) index.
     */
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('source', 32);
            $table->json('project_ids');
            $table->json('query');
            $table->string('condition', 2);
            $table->decimal('threshold', 24, 6);
            $table->unsignedInteger('window_seconds');
            $table->unsignedInteger('interval_seconds')->default(60);
            $table->unsignedInteger('for_seconds')->default(0);
            $table->unsignedInteger('keep_firing_for_seconds')->default(0);
            $table->string('no_data_state', 16)->default('inactive');
            $table->string('severity', 16)->default('warning');
            $table->boolean('enabled')->default(true);
            $table->timestamp('muted_until')->nullable();
            $table->timestamp('next_evaluation_at')->nullable();
            $table->timestamp('last_evaluated_at')->nullable();
            $table->string('health', 16)->default('unknown');
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['enabled', 'next_evaluation_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};
