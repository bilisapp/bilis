<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Which services live in which repository.
     *
     * A project is one product and ships several services; those services do
     * not have to share a codebase. `project_repositories` has always been a
     * `hasMany` — what was missing was not room for a second repository but the
     * one fact that makes a second one usable: given an error, which repository
     * is supposed to fix it.
     *
     * `ServiceName` is the answer, and it costs nothing to ask: it is on every
     * OTel row already and is part of the error fingerprint. This table maps it
     * to a repository, many-to-one, so a monorepo shipping three services is
     * three rows pointing at one repository.
     *
     * One repository per project may hold the sentinel `*` instead of a name:
     * the catch-all, meaning "every service that no other repository has
     * claimed". It is what a project with a single repository wants — name
     * nothing, scan everything — and it stays useful afterwards, as the main
     * repository of a project whose other services have peeled off into their
     * own. The uniqueness rule below makes it exactly one per project.
     *
     * `project_id` is denormalised from the parent row for one reason: the
     * unique constraint. "One service resolves to exactly one repository" is a
     * property of the PROJECT, not of a repository, and the scan needs it to be
     * true — two repositories claiming `checkout` would each raise a job for
     * every checkout error. A repository's project never changes (connecting a
     * different repository creates a new row rather than repointing one), so
     * the copy cannot drift.
     */
    public function up(): void
    {
        Schema::create('project_repository_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_repository_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('service_name');
            $table->timestamps();

            $table->unique(['project_id', 'service_name']);
            $table->index('project_repository_id');
        });

        $this->claimExistingServices();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_repository_services');
    }

    /**
     * Leave every existing repository scanning exactly what it scanned before.
     *
     * Until now one project meant one repository, and the scan read every error
     * in the project regardless of service. A repository with no services
     * mapped now scans nothing, so migrating without this would silently switch
     * autofix off for every install that already had it on — the worst possible
     * shape for this change to arrive in.
     *
     * The catch-all row is precisely the old behaviour, and remains the right
     * answer for a single-repository project. Connecting a second repository
     * and naming its services narrows the catch-all automatically, because the
     * catch-all is defined as what nobody else has claimed.
     */
    private function claimExistingServices(): void
    {
        DB::table('project_repositories')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->each(function (object $repository): void {
                DB::table('project_repository_services')->insert([
                    'project_repository_id' => $repository->id,
                    'project_id' => $repository->project_id,
                    'service_name' => '*',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }
};
