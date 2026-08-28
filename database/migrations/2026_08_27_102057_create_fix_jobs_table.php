<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fix_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_repository_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint')->index();
            $table->json('error_context');
            $table->string('base_sha');
            $table->string('status')->index();
            $table->text('diff')->nullable();
            $table->json('report')->nullable();
            $table->json('events')->nullable();
            $table->unsignedInteger('pr_number')->nullable();
            $table->string('pr_url')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fix_jobs');
    }
};
