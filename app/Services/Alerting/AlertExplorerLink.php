<?php

namespace App\Services\Alerting;

use App\Models\AlertRule;
use App\Models\Project;
use App\Models\Team;
use Carbon\CarbonImmutable;

/**
 * The absolute URL of the explorer page that shows what a rule read: its
 * source's page, over the evaluated window, with the rule's filters and the
 * instance's labels applied as far as that page's query string can say them.
 *
 * Built with named routes and the same query keys the pages read
 * (`LogFilters`, `TraceFilters`, `MetricFilters::fromRequest()`), so a link
 * never invents a parameter the page would ignore.
 */
final class AlertExplorerLink
{
    /**
     * @param  array<string, string>  $labels
     */
    public static function forRule(AlertRule $rule, Team $team, array $labels, CarbonImmutable $from, CarbonImmutable $to): ?string
    {
        $query = $rule->query;
        $filters = is_array($query['filters'] ?? null) ? $query['filters'] : [];

        $base = array_filter([
            'current_team' => $team->slug,
            'project' => self::projectSlug($rule, $team),
            'from' => $from->toIso8601ZuluString(),
            'to' => $to->toIso8601ZuluString(),
        ], fn (?string $value): bool => $value !== null);

        return match ($rule->source) {
            'logs' => route('logs.index', array_filter([
                ...$base,
                'service' => $labels['service.name'] ?? self::single($filters['services'] ?? null),
                'severity' => is_array($filters['severities'] ?? null) && $filters['severities'] !== [] ? array_values($filters['severities']) : null,
                'search' => is_string($filters['search'] ?? null) && trim($filters['search']) !== '' ? trim($filters['search']) : null,
            ], fn (mixed $value): bool => $value !== null)),
            'traces' => route('traces.index', array_filter([
                ...$base,
                'service' => $labels['service.name'] ?? self::single($filters['services'] ?? null),
                'errors' => ($query['aggregation'] ?? null) === 'error_ratio' ? 1 : null,
            ], fn (mixed $value): bool => $value !== null)),
            'metrics' => route('metrics.index', array_filter([
                ...$base,
                'metric' => is_string($query['metric'] ?? null) ? $query['metric'] : null,
                'where' => [...$filters, ...$labels] ?: null,
                'group_by' => is_string($query['group_by'] ?? null) && $query['group_by'] !== '' ? $query['group_by'] : null,
            ], fn (mixed $value): bool => $value !== null)),
            default => null,
        };
    }

    /**
     * The project filter, only when the rule reads exactly one project that
     * still belongs to its team; otherwise (several, or null for all of them)
     * the page shows all of them.
     */
    private static function projectSlug(AlertRule $rule, Team $team): ?string
    {
        if ($rule->project_ids === null || count($rule->project_ids) !== 1) {
            return null;
        }

        $slug = Project::query()
            ->where('team_id', $team->id)
            ->whereKey($rule->project_ids[0])
            ->value('slug');

        return is_string($slug) ? $slug : null;
    }

    /**
     * The one value of a list filter, or null when it holds none or several
     * (the explorers filter on one service).
     */
    private static function single(mixed $values): ?string
    {
        return is_array($values) && count($values) === 1 && is_string($values[array_key_first($values)])
            ? $values[array_key_first($values)]
            : null;
    }
}
