<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesScope;
use App\Services\Metrics\MetricFilters;
use App\Services\Metrics\MetricQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

/**
 * The metric catalog, for an agent that does not yet know what a project
 * measures.
 *
 * The window defaults to the last 24 hours rather than the hour every other
 * tool uses: this is discovery, and `MetricQuery::catalog()` looks back a day
 * anyway, so a shorter default would only make the answer look narrower than
 * it is.
 */
#[Name('list-metrics')]
#[Title('List metrics')]
#[Description('List the OTel metrics a project has reported — name, type, unit, the services sending it, and how query-metric will read it (a cumulative counter becomes a per-second rate, a histogram becomes p50/p95/p99). Pass "metric" to also get that metric\'s attribute keys with sample values, which is what query-metric\'s "where" and "group_by" take. Call this before query-metric: a guessed metric name matches nothing.')]
class ListMetricsTool extends Tool
{
    use ResolvesScope;

    /**
     * The default window, in minutes: a day, for discovery.
     */
    private const DEFAULT_WINDOW_MINUTES = 24 * 60;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, MetricQuery $metrics): Response
    {
        $scope = $this->resolveScope($request);

        if ($scope instanceof Response) {
            return $scope;
        }

        $request->validate([
            'service' => ['sometimes', 'string', 'max:255'],
            'metric' => ['sometimes', 'string', 'max:255'],
        ]);

        [$from, $to] = self::clampWindow(...$this->window($request, self::DEFAULT_WINDOW_MINUTES));

        $service = $this->argument($request, 'service');
        $metric = $this->argument($request, 'metric');

        $catalog = $metrics->catalog($scope->projectIds, new MetricFilters(from: $from, to: $to));

        $entries = array_values(array_filter(
            $catalog['metrics'],
            fn (array $entry): bool => $service === null || in_array($service, $entry['services'], true),
        ));

        $payload = [
            'team' => $scope->team->slug,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'unavailable' => $catalog['unavailable'],
            'count' => count($entries),
            'metrics' => array_map(fn (array $entry): array => [
                'name' => $entry['name'],
                'type' => $entry['type'],
                'unit' => $entry['unit'],
                'description' => $entry['description'],
                'services' => $entry['services'],
                'readsAs' => self::readsAs($entry['type'], $entry['monotonic'], $entry['temporality']),
                'points' => $entry['points'],
            ], $entries),
        ];

        if ($metric !== null) {
            $attributes = $metrics->attributes($scope->projectIds, new MetricFilters(
                service: $service,
                metric: $metric,
                from: $from,
                to: $to,
            ));

            $payload['attributes'] = [
                'metric' => $metric,
                'unavailable' => $attributes['unavailable'],
                'keys' => $attributes['attributes'],
            ];
        }

        return Response::json($payload);
    }

    /**
     * How query-metric will chart a metric of this shape, in plain words.
     *
     * Mirrors the branches of `MetricQuery::series()`: temporality 2 is
     * cumulative, anything else is read as delta.
     */
    public static function readsAs(string $type, bool $monotonic, int $temporality): string
    {
        $cumulative = $temporality === 2;

        return match (true) {
            $type === 'gauge' => 'gauge: a sampled level, charted as its value (series combined with "agg": avg by default)',
            $type === 'sum' && $cumulative && $monotonic => 'cumulative counter: queried as a per-second rate',
            $type === 'sum' && $cumulative => 'up-down counter: charted as its current level (series combined with "agg")',
            $type === 'sum' => 'delta counter: queried as a per-second rate',
            $type === 'histogram', $type === 'exponential_histogram' => 'distribution: queried as p50, p95 and p99 per time bucket',
            $type === 'summary' => 'summary: the stored quantiles, averaged across series (approximate)',
            default => $type,
        };
    }

    /**
     * Cap a window at the metrics tables' retention, keeping its end.
     *
     * @return array{Carbon, Carbon}
     */
    public static function clampWindow(Carbon $from, Carbon $to): array
    {
        $earliest = $to->copy()->subDays(MetricFilters::MAX_RANGE_DAYS);

        return [$from->lessThan($earliest) ? $earliest : $from, $to];
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'team' => $schema->string()->description('Team slug. Omit to use the current team.'),
            'project' => $schema->string()->description('Project slug. Omit to read every project in the team.'),
            'service' => $schema->string()->description('Only metrics this service reports.'),
            'metric' => $schema->string()->description('A metric name from this list: also return its attribute keys, each with its most common values.'),
            'from' => $schema->string()->description('Start of the window, ISO-8601. Defaults to 24 hours before "to"; at most 30 days before it.'),
            'to' => $schema->string()->description('End of the window, ISO-8601. Defaults to now.'),
        ];
    }
}
