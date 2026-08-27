<?php

namespace App\Models;

use Database\Factories\ProjectRepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The repository an autofix attempt for a project is allowed to work on.
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
 * @property-read Project $project
 * @property-read GitHubInstallation $installation
 * @property-read Collection<int, FixJob> $fixJobs
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
    use HasFactory;

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
