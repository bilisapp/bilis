<?php

namespace App\Models;

use Database\Factories\ProjectRepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The repository an autofix attempt for a project is allowed to work on.
 *
 * Disconnecting soft deletes the row rather than removing it. `fix_jobs`
 * cascades from here, and the jobs already raised are both the history of what
 * was attempted and the fingerprint cooldown the scan reads — a disconnect may
 * not destroy either. Every "is this project connected?" query therefore reads
 * the default scope, and `FixJob::repository()` reaches past it.
 *
 * @property int $id
 * @property int $project_id
 * @property int $github_installation_id
 * @property string $repo_full_name
 * @property string $default_branch
 * @property bool $autofix_enabled
 * @property string|null $test_cmd
 * @property int $max_concurrent
 * @property int $daily_budget
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Project $project
 * @property-read GitHubInstallation $installation
 * @property-read Collection<int, FixJob> $fixJobs
 * @property-read Collection<int, ProjectRepositoryService> $services
 */
#[Fillable([
    'project_id',
    'github_installation_id',
    'repo_full_name',
    'default_branch',
    'autofix_enabled',
    'test_cmd',
    'max_concurrent',
    'daily_budget',
])]
class ProjectRepository extends Model
{
    /** @use HasFactory<ProjectRepositoryFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the project the repository is connected to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the GitHub App installation the repository is reached through.
     *
     * @return BelongsTo<GitHubInstallation, $this>
     */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(GitHubInstallation::class, 'github_installation_id');
    }

    /**
     * Get the fix jobs attempted against this repository.
     *
     * @return HasMany<FixJob, $this>
     */
    public function fixJobs(): HasMany
    {
        return $this->hasMany(FixJob::class);
    }

    /**
     * The services this repository is responsible for.
     *
     * @return HasMany<ProjectRepositoryService, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(ProjectRepositoryService::class);
    }

    /**
     * The service names this repository has claimed by name.
     *
     * The catch-all is not one of them: it is the absence of a name, and the
     * scan handles it by subtraction rather than by listing.
     *
     * @return list<string>
     */
    public function namedServices(): array
    {
        /** @var list<string> $names */
        $names = $this->services
            ->reject(fn (ProjectRepositoryService $service): bool => $service->isCatchAll())
            ->map(fn (ProjectRepositoryService $service): string => $service->service_name)
            ->values()
            ->all();

        return $names;
    }

    /**
     * Whether this repository takes every service nobody else has named.
     */
    public function isCatchAll(): bool
    {
        return $this->services->contains(
            fn (ProjectRepositoryService $service): bool => $service->isCatchAll(),
        );
    }

    /**
     * Which services the scan should read for this repository.
     *
     * Two shapes, and the caller has to tell them apart:
     *
     * - `include` — read only these services. A repository that named its
     *   services reads exactly those, and one that named nothing reads nothing
     *   at all rather than quietly inheriting the project.
     * - `exclude` — read everything except these. This is the catch-all, and
     *   the exclusions are the services its sibling repositories have claimed,
     *   which is what stops one error raising two jobs.
     *
     * @return array{include: list<string>|null, exclude: list<string>}
     */
    public function scanScope(): array
    {
        if (! $this->isCatchAll()) {
            return ['include' => $this->namedServices(), 'exclude' => []];
        }

        /** @var list<string> $claimedElsewhere */
        $claimedElsewhere = ProjectRepositoryService::query()
            ->where('project_id', $this->project_id)
            ->where('project_repository_id', '!=', $this->getKey())
            ->where('service_name', '!=', ProjectRepositoryService::CATCH_ALL)
            ->get()
            ->map(fn (ProjectRepositoryService $service): string => $service->service_name)
            ->values()
            ->all();

        return ['include' => null, 'exclude' => $claimedElsewhere];
    }

    /**
     * Whether this repository can be scanned at all.
     *
     * An autofix-enabled repository that claims no service is a repository the
     * scan will never raise anything for. That is a settings mistake rather
     * than a state to design around, so it is refused at the point of enabling
     * and surfaced in project settings — but the scan still checks, because a
     * sibling repository claiming the last service can empty this one out
     * without anyone touching its own settings.
     */
    public function hasScannableServices(): bool
    {
        $scope = $this->scanScope();

        return $scope['include'] === null || $scope['include'] !== [];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'autofix_enabled' => 'boolean',
            'max_concurrent' => 'integer',
            'daily_budget' => 'integer',
        ];
    }
}
