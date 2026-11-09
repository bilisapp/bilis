<?php

namespace App\Models;

use App\Enums\AlertCondition;
use App\Enums\AlertEventType;
use App\Enums\AlertSeverity;
use Database\Factories\AlertEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of alert history. Append-only, so there is no `updated_at`.
 *
 * `dedupe_key` is unique: `rule:{id}:{labels_hash}:{type}:{evaluated_at}` for
 * rule events; `issue:{project}:{fingerprint}:new` and
 * `issue:{project}:{fingerprint}:regressed:{resolved_at unix}` for issue
 * events (`IssueAlertScan`). A retried job therefore writes, and notifies, nothing twice.
 *
 * Pruned by the scheduled `model:prune` after `alerting.events_retention_days`.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $alert_rule_id
 * @property int|null $alert_instance_id
 * @property AlertEventType $type
 * @property array<string, string> $labels
 * @property float|null $value
 * @property float|null $threshold
 * @property AlertCondition|null $condition the rule's comparison when recorded (null: issue event or an older row)
 * @property AlertSeverity|null $severity the rule's severity when recorded (null: issue event or an older row)
 * @property Carbon $occurred_at
 * @property array<string, mixed>|null $context
 * @property string $dedupe_key
 * @property Carbon|null $created_at
 * @property-read Team $team
 * @property-read AlertRule|null $rule
 * @property-read AlertInstance|null $instance
 */
#[Fillable([
    'team_id',
    'alert_rule_id',
    'alert_instance_id',
    'type',
    'labels',
    'value',
    'threshold',
    'condition',
    'severity',
    'occurred_at',
    'context',
    'dedupe_key',
])]
class AlertEvent extends Model
{
    /** @use HasFactory<AlertEventFactory> */
    use HasFactory, MassPrunable;

    /**
     * History is never updated.
     */
    public const UPDATED_AT = null;

    /**
     * Events past the retention window.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('occurred_at', '<', now()->subDays((int) config('alerting.events_retention_days', 90)));
    }

    /**
     * Get the team the event belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the rule that raised the event, while it still exists.
     *
     * @return BelongsTo<AlertRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }

    /**
     * Get the series the event is about, while it still exists.
     *
     * @return BelongsTo<AlertInstance, $this>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(AlertInstance::class, 'alert_instance_id');
    }

    /**
     * The comparison the event was judged by: the snapshot taken when it was
     * recorded, or — for rows older than the snapshot — the rule's current one.
     */
    public function judgedCondition(): ?AlertCondition
    {
        return $this->condition ?? $this->rule?->condition;
    }

    /**
     * The severity the event was raised with; see {@see judgedCondition()}.
     */
    public function judgedSeverity(): ?AlertSeverity
    {
        return $this->severity ?? $this->rule?->severity;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AlertEventType::class,
            'labels' => 'array',
            'value' => 'float',
            'threshold' => 'float',
            'condition' => AlertCondition::class,
            'severity' => AlertSeverity::class,
            'occurred_at' => 'datetime',
            'context' => 'array',
        ];
    }
}
