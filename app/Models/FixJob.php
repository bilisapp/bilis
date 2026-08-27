<?php

namespace App\Models;

use App\Enums\FixJobStatus;
use App\Enums\FixJobType;
use Database\Factories\FixJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt at one unit of work in a repository.
 *
 * Two things raise one. An `error` job comes from the scheduled scan and
 * carries the fingerprint and error context that justified it. A `custom` job
 * comes from a person and carries their `instructions` instead — no
 * fingerprint, no error context. Everything after dispatch is the same.
 *
 * The `uuid` is both the route key and the idempotency key Ayos is dispatched
 * with, so the callback can find the row without a database id leaking out.
 *
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property int $project_repository_id
 * @property FixJobType $type
 * @property string|null $fingerprint
 * @property array<string, mixed>|null $error_context
 * @property string|null $instructions
 * @property string $base_sha
 * @property int $redispatch_count
 * @property FixJobStatus $status
 * @property string|null $diff
 * @property array<string, mixed>|null $report
 * @property array<int, array<string, mixed>>|null $events
 * @property array<string, mixed>|null $verification
 * @property int|null $pr_number
 * @property string|null $pr_url
 * @property string|null $failure_reason
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read ProjectRepository $repository
 */
#[Fillable([
    'uuid',
    'project_id',
    'project_repository_id',
    'type',
    'fingerprint',
    'error_context',
    'instructions',
    'base_sha',
    'redispatch_count',
    'status',
    'diff',
    'report',
    'events',
    'verification',
    'pr_number',
    'pr_url',
    'failure_reason',
    'dispatched_at',
    'completed_at',
    'verified_at',
])]
class FixJob extends Model
{
    /** @use HasFactory<FixJobFactory> */
    use HasFactory, HasUuids;

    /**
     * The columns that should receive a generated unique identifier.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Get the project the fix job was raised for.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the repository the fix job works on.
     *
     * @return BelongsTo<ProjectRepository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(ProjectRepository::class, 'project_repository_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'error_context' => 'array',
            'report' => 'array',
            'events' => 'array',
            'verification' => 'array',
            'status' => FixJobStatus::class,
            'type' => FixJobType::class,
            'pr_number' => 'integer',
            'redispatch_count' => 'integer',
            'dispatched_at' => 'datetime',
            'completed_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }
}
