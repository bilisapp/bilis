<?php

namespace App\Services\Metrics;

use App\Services\Traces\TraceFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The user supplied, already validated criteria for one metric chart.
 *
 * Shaped like {@see TraceFilters} so the shared toolbar pieces — project
 * picker, service field, time range — write the same query string on every
 * page. What is new is the metric itself, attribute equality filters
 * (`where[key]=value`), one attribute to split the chart by (`group_by`), and
 * how a gauge's series are combined (`agg`).
 *
 * The window is capped at the tables' retention (SCHEMA.md R16): a wider one
 * cannot contain more data, only a longer scan.
 */
class MetricFilters
{
    /**
     * The default window when the request does not specify one.
     */
    public const DEFAULT_RANGE_MINUTES = 60;

    /**
     * The widest window a chart may ask for: the metrics tables' TTL.
     */
    public const MAX_RANGE_DAYS = 30;

    /**
     * How many attribute equality filters one chart may carry.
     */
    public const MAX_WHERE = 5;

    /**
     * How a gauge's series are combined into one line per group.
     */
    public const AGGREGATIONS = ['avg', 'min', 'max', 'sum'];

    /**
     * @param  array<string, string>  $where
     */
    public function __construct(
        public readonly ?string $project = null,
        public readonly ?string $service = null,
        public readonly ?string $metric = null,
        public readonly array $where = [],
        public readonly ?string $groupBy = null,
        public readonly string $aggregation = 'avg',
        public readonly Carbon $from = new Carbon,
        public readonly Carbon $to = new Carbon,
    ) {}

    /**
     * Build the filters from the request query string, falling back to defaults.
     */
    public static function fromRequest(Request $request): self
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'project' => ['nullable', 'string', 'max:255'],
            'service' => ['nullable', 'string', 'max:255'],
            'metric' => ['nullable', 'string', 'max:255'],
            'where' => ['nullable', 'array', 'max:'.self::MAX_WHERE],
            'where.*' => ['nullable', 'string', 'max:255'],
            'group_by' => ['nullable', 'string', 'max:255'],
            'agg' => ['nullable', 'string', 'in:'.implode(',', self::AGGREGATIONS)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $to = isset($validated['to']) ? Carbon::parse((string) $validated['to']) : Carbon::now();
        $from = isset($validated['from'])
            ? Carbon::parse((string) $validated['from'])
            : (clone $to)->subMinutes(self::DEFAULT_RANGE_MINUTES);

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $earliest = (clone $to)->subDays(self::MAX_RANGE_DAYS);

        if ($from->lessThan($earliest)) {
            $from = $earliest;
        }

        return new self(
            project: self::trimmedOrNull($validated['project'] ?? null),
            service: self::trimmedOrNull($validated['service'] ?? null),
            metric: self::trimmedOrNull($validated['metric'] ?? null),
            where: self::where($validated['where'] ?? []),
            groupBy: self::trimmedOrNull($validated['group_by'] ?? null),
            aggregation: is_string($validated['agg'] ?? null) ? $validated['agg'] : 'avg',
            from: $from,
            to: $to,
        );
    }

    /**
     * The filters as they should be handed back to the client.
     *
     * @return array{project: string|null, service: string|null, metric: string|null, where: array<string, string>, groupBy: string|null, agg: string, from: string, to: string}
     */
    public function toArray(): array
    {
        return [
            'project' => $this->project,
            'service' => $this->service,
            'metric' => $this->metric,
            'where' => $this->where,
            'groupBy' => $this->groupBy,
            'agg' => $this->aggregation,
            'from' => $this->from->toIso8601String(),
            'to' => $this->to->toIso8601String(),
        ];
    }

    /**
     * Attribute filters with blank keys and values dropped.
     *
     * @return array<string, string>
     */
    private static function where(mixed $where): array
    {
        if (! is_array($where)) {
            return [];
        }

        $filters = [];

        foreach ($where as $key => $value) {
            $key = trim((string) $key);
            $value = is_string($value) ? trim($value) : '';

            if ($key !== '' && $value !== '') {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    private static function trimmedOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
