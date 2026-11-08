<?php

namespace App\Services\Alerting;

use App\Enums\AlertSeverity;
use App\Enums\AlertState;
use App\Models\AlertInstance;
use App\Models\Team;
use Carbon\CarbonImmutable;

/**
 * The dashboard's "Needs attention" list (specs/alerting-defaults.md §5): the
 * team's firing series, then its pending ones, most severe first.
 *
 * Reads the app database only, and ignores channels on purpose — a team that
 * has nowhere to send an alert still sees on its dashboard what is firing.
 * Rules that are switched off are left out, as {@see AlertBadge} leaves them
 * out: nothing is evaluating them any more. Muted rules stay in: muting
 * silences delivery, not the truth.
 *
 * @phpstan-type NeedsAttentionList array{items: list<array<string, mixed>>, total: int}
 */
class NeedsAttention
{
    public const LIMIT = 8;

    /**
     * Firing before pending, then critical > warning > info, then the most
     * recent first — the order the Alerts page lists them in within a state.
     *
     * @return NeedsAttentionList
     */
    public function forTeam(Team $team): array
    {
        $instances = AlertInstance::query()
            ->with('rule')
            ->whereIn('state', [AlertState::Firing, AlertState::Pending])
            ->whereHas('rule', fn ($query) => $query->where('team_id', $team->getKey())->where('enabled', true))
            ->get()
            ->sortBy(fn (AlertInstance $instance): array => [
                $instance->state === AlertState::Firing ? 0 : 1,
                $this->severityRank($instance->rule->severity),
                -(int) $this->since($instance)?->getTimestamp(),
                $instance->id,
            ])
            ->values();

        $now = CarbonImmutable::now('UTC');

        return [
            'items' => array_values($instances->take(self::LIMIT)->map(fn (AlertInstance $instance): array => [
                ...$instance->toSummary(),
                'explorerHref' => $this->explorerHref($instance, $team, $now),
                'ruleHref' => route('alerts.edit', ['current_team' => $team->slug, 'alertRule' => $instance->rule->id]),
            ])->all()),
            'total' => $instances->count(),
        ];
    }

    /**
     * The explorer from one window before the series turned active until now:
     * the reading that tripped it, and everything since.
     */
    private function explorerHref(AlertInstance $instance, Team $team, CarbonImmutable $now): ?string
    {
        $since = $this->since($instance);
        $from = ($since !== null ? CarbonImmutable::instance($since) : $now)
            ->utc()
            ->subSeconds($instance->rule->window_seconds);

        return AlertExplorerLink::forRule($instance->rule, $team, $instance->labels, $from, $now);
    }

    /**
     * Critical first, then warning, then info.
     */
    private function severityRank(AlertSeverity $severity): int
    {
        return match ($severity) {
            AlertSeverity::Critical => 0,
            AlertSeverity::Warning => 1,
            AlertSeverity::Info => 2,
        };
    }

    private function since(AlertInstance $instance): ?\DateTimeInterface
    {
        return $instance->state === AlertState::Firing
            ? ($instance->fired_at ?? $instance->active_at)
            : $instance->active_at;
    }
}
