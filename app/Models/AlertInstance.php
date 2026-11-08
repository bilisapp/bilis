<?php

namespace App\Models;

use App\Enums\AlertState;
use App\Services\Alerting\AlertInstanceState;
use App\Services\Alerting\Labels;
use Database\Factories\AlertInstanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One series a rule has produced — Prometheus' "active alert".
 *
 * Keyed per rule by `labels_hash` ({@see Labels::hash()}).
 * A known instance with no value in an evaluation is the no-data case.
 *
 * @property int $id
 * @property int $alert_rule_id
 * @property array<string, string> $labels
 * @property string $labels_hash
 * @property AlertState $state
 * @property float|null $value
 * @property Carbon|null $active_at
 * @property Carbon|null $fired_at
 * @property Carbon|null $keep_firing_since
 * @property Carbon|null $last_seen_with_data_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AlertRule $rule
 * @property-read Collection<int, AlertEvent> $events
 */
#[Fillable([
    'alert_rule_id',
    'labels',
    'labels_hash',
    'state',
    'value',
    'active_at',
    'fired_at',
    'keep_firing_since',
    'last_seen_with_data_at',
    'resolved_at',
])]
class AlertInstance extends Model
{
    /** @use HasFactory<AlertInstanceFactory> */
    use HasFactory;

    /**
     * The series as the Alerts page and the dashboard list it, with the rule
     * it belongs to (eager-load `rule`). Never carries a channel.
     *
     * @return array<string, mixed>
     */
    public function toSummary(): array
    {
        $rule = $this->rule;

        return [
            'id' => $this->id,
            'ruleId' => $rule->id,
            'ruleName' => $rule->name,
            'source' => $rule->source,
            'query' => (object) $rule->query,
            'condition' => $rule->condition->value,
            'threshold' => $rule->threshold,
            'severity' => $rule->severity->value,
            'muted' => $rule->isMutedAt(now()),
            'labels' => (object) $this->labels,
            'state' => $this->state->value,
            'value' => $this->value,
            'activeAt' => $this->active_at?->toIso8601String(),
            'firedAt' => $this->fired_at?->toIso8601String(),
        ];
    }

    /**
     * The instance's state as the state machine reads it.
     */
    public function toState(): AlertInstanceState
    {
        return new AlertInstanceState(
            state: $this->state,
            activeAt: $this->active_at?->toImmutable(),
            firedAt: $this->fired_at?->toImmutable(),
            keepFiringSince: $this->keep_firing_since?->toImmutable(),
            resolvedAt: $this->resolved_at?->toImmutable(),
        );
    }

    /**
     * Copy the state machine's output onto the row (unsaved).
     */
    public function applyState(AlertInstanceState $state): static
    {
        return $this->fill([
            'state' => $state->state,
            'active_at' => $state->activeAt,
            'fired_at' => $state->firedAt,
            'keep_firing_since' => $state->keepFiringSince,
            'resolved_at' => $state->resolvedAt,
        ]);
    }

    /**
     * Get the rule that produced this series.
     *
     * @return BelongsTo<AlertRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }

    /**
     * Get the events recorded for this series.
     *
     * @return HasMany<AlertEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AlertEvent::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'labels' => 'array',
            'state' => AlertState::class,
            'value' => 'float',
            'active_at' => 'datetime',
            'fired_at' => 'datetime',
            'keep_firing_since' => 'datetime',
            'last_seen_with_data_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
