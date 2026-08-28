<?php

namespace App\Models;

use Database\Factories\ProjectRepositoryServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One service name claimed by one repository.
 *
 * This is what makes a project with several repositories answerable: given an
 * error, which codebase is supposed to fix it. `ServiceName` is on every OTel
 * row and is already part of the fingerprint, so nothing has to be inferred.
 *
 * A row holding `*` is the catch-all: that repository takes every service no
 * other repository in the project has named. `unique(project_id, service_name)`
 * makes it at most one per project, and makes a named claim beat it — the scan
 * subtracts the named services from what the catch-all reads.
 *
 * @property int $id
 * @property int $project_repository_id
 * @property int $project_id
 * @property string $service_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProjectRepository $repository
 * @property-read Project $project
 */
#[Fillable(['project_repository_id', 'project_id', 'service_name'])]
class ProjectRepositoryService extends Model
{
    /** @use HasFactory<ProjectRepositoryServiceFactory> */
    use HasFactory;

    /**
     * The claim that means "every service nobody else has named".
     */
    public const CATCH_ALL = '*';

    /**
     * Whether this row is the catch-all rather than a named service.
     */
    public function isCatchAll(): bool
    {
        return $this->service_name === self::CATCH_ALL;
    }

    /**
     * The repository this service is fixed in.
     *
     * @return BelongsTo<ProjectRepository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(ProjectRepository::class, 'project_repository_id');
    }

    /**
     * The project the claim is unique within.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
