<?php

namespace App\Models;

use App\Enums\IssueStatus;
use Database\Factories\IssueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person's decision about one issue: resolved, ignored or reopened.
 *
 * The issue itself lives in ClickHouse (`issue_summary`), keyed by the same
 * project id and fingerprint. A row exists here only once someone has acted on
 * the issue, so an issue with no row is unresolved.
 *
 * @property int $id
 * @property int $project_id
 * @property string $fingerprint
 * @property IssueStatus $status
 * @property Carbon|null $resolved_at
 * @property int|null $status_changed_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read User|null $statusChangedBy
 */
#[Fillable([
    'project_id',
    'fingerprint',
    'status',
    'resolved_at',
    'status_changed_by',
])]
class Issue extends Model
{
    /** @use HasFactory<IssueFactory> */
    use HasFactory;

    /**
     * Get the project the issue belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the person who last changed the status.
     *
     * @return BelongsTo<User, $this>
     */
    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IssueStatus::class,
            'resolved_at' => 'datetime',
        ];
    }
}
