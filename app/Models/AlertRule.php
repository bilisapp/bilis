<?php

namespace App\Models;

use App\Enums\AlertCondition;
use App\Enums\AlertRuleHealth;
use App\Enums\AlertSeverity;
use App\Enums\NoDataState;
use Database\Factories\AlertRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One alert rule: a structured query against one source, a condition, and
 * Prometheus' `for` / `keep_firing_for`.
 *
 * `query` is the source's structured form — filters, aggregation, field,
 * group-by — and never SQL. `project_ids` is re-validated against the team's
 * projects on save and on every evaluation; it is not a tenancy boundary.
 * Null means every project of the team, including ones created later.
 *
 * `template_key` / `template_version` mark a rule Bilis set up from its
 * catalogue; `customized_at` is set once a person edits it
 * (specs/alerting-defaults.md).
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $source
 * @property list<int>|null $project_ids
 * @property array<string, mixed> $query
 * @property AlertCondition $condition
 * @property float $threshold
 * @property int $window_seconds
 * @property int $interval_seconds
 * @property int $for_seconds
 * @property int $keep_firing_for_seconds
 * @property NoDataState $no_data_state
 * @property AlertSeverity $severity
 * @property bool $enabled
 * @property Carbon|null $muted_until
 * @property Carbon|null $next_evaluation_at
 * @property Carbon|null $last_evaluated_at
 * @property AlertRuleHealth $health
 * @property string|null $last_error
 * @property int|null $created_by
 * @property string|null $template_key
 * @property int|null $template_version
 * @property Carbon|null $customized_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User|null $creator
 * @property-read Collection<int, AlertInstance> $instances
 * @property-read Collection<int, AlertEvent> $events
 * @property-read Collection<int, NotificationChannel> $notificationChannels
 */
#[Fillable([
    'team_id',
    'name',
    'source',
    'project_ids',
    'query',
    'condition',
    'threshold',
    'window_seconds',
    'interval_seconds',
    'for_seconds',
    'keep_firing_for_seconds',
    'no_data_state',
    'severity',
    'enabled',
    'muted_until',
    'next_evaluation_at',
    'last_evaluated_at',
    'health',
    'last_error',
    'created_by',
    'template_key',
    'template_version',
    'customized_at',
])]
class AlertRule extends Model
{
    /** @use HasFactory<AlertRuleFactory> */
    use HasFactory;

    /**
     * Whether the rule is muted at the given moment: it still evaluates and
     * records, but sends nothing.
     */
    public function isMutedAt(\DateTimeInterface $at): bool
    {
        return $this->muted_until !== null && $this->muted_until->greaterThan($at);
    }

    /**
     * Whether Bilis set this rule up from its template catalogue. Such a rule
     * is turned off (`enabled = false`), never deleted, so `alerts:provision`
     * does not install it again.
     */
    public function isTemplate(): bool
    {
        return $this->template_key !== null;
    }

    /**
     * The catalogue entry this rule was set up from, or null for a rule a
     * person built (or a template since removed). Template keys contain dots
     * (`logs.error_spike`), so read the catalogue by array key — a dot path
     * like `config("alerting_templates.templates.{$key}.why")` finds nothing.
     *
     * @return array{version: int, requires: string, name: string, why: string, rule: array<string, mixed>}|null
     */
    public function template(): ?array
    {
        if ($this->template_key === null) {
            return null;
        }

        /** @var array<string, array{version: int, requires: string, name: string, why: string, rule: array<string, mixed>}> $templates */
        $templates = (array) config('alerting_templates.templates', []);

        return $templates[$this->template_key] ?? null;
    }

    /**
     * Get the team the rule belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the person who created the rule.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the series this rule has produced.
     *
     * @return HasMany<AlertInstance, $this>
     */
    public function instances(): HasMany
    {
        return $this->hasMany(AlertInstance::class);
    }

    /**
     * Get the rule's history.
     *
     * @return HasMany<AlertEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AlertEvent::class);
    }

    /**
     * Get the channels this rule notifies.
     *
     * @return BelongsToMany<NotificationChannel, $this>
     */
    public function notificationChannels(): BelongsToMany
    {
        return $this->belongsToMany(NotificationChannel::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'project_ids' => 'array',
            'query' => 'array',
            'condition' => AlertCondition::class,
            'threshold' => 'float',
            'window_seconds' => 'integer',
            'interval_seconds' => 'integer',
            'for_seconds' => 'integer',
            'keep_firing_for_seconds' => 'integer',
            'no_data_state' => NoDataState::class,
            'severity' => AlertSeverity::class,
            'enabled' => 'boolean',
            'muted_until' => 'datetime',
            'next_evaluation_at' => 'datetime',
            'last_evaluated_at' => 'datetime',
            'health' => AlertRuleHealth::class,
            'template_version' => 'integer',
            'customized_at' => 'datetime',
        ];
    }
}
