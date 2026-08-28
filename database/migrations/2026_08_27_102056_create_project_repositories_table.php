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
        Schema::create('project_repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('github_installation_id')->constrained()->cascadeOnDelete();
            $table->string('repo_full_name');
            $table->string('default_branch')->default('main');
            $table->boolean('autofix_enabled')->default(false);
            $table->string('test_cmd')->nullable();
            $table->unsignedInteger('max_concurrent')->default(1);
            $table->unsignedInteger('daily_budget')->default(5);
            $table->timestamps();

            $table->unique(['project_id', 'repo_full_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_repositories');
    }
};
