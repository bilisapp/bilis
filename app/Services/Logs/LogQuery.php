<?php

namespace App\Services\Logs;

use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use Illuminate\Support\Carbon;

/**
 * Builds and runs the parameterized ClickHouse statements behind the log viewer.
 *
 * Every statement is constrained to an explicit list of project ids resolved on
 * the server, and every user supplied value is bound as a ClickHouse query
 * parameter rather than being interpolated into the SQL.
 *
 * @phpstan-type LogRow array{timestamp: string, traceId: string, spanId: string, severityText: string, severityNumber: int, serviceName: string, body: string, scopeName: string, scopeVersion: string, resourceAttributes: array<string, string>, logAttributes: array<string, string>, projectId: int}
 * @phpstan-type LogResult array{rows: list<LogRow>, nextCursor: string|null, unavailable: bool}
 * @phpstan-type HistogramBucket array{bucket: string, counts: array<string, int>, total: int}
 * @phpstan-type HistogramResult array{buckets: list<HistogramBucket>, intervalSeconds: int, total: int, unavailable: bool}
 */
class LogQuery
{
    /**
     * The columns projected by every log statement.
     */
    private const COLUMNS = 'ProjectId, Timestamp, TraceId, SpanId, SeverityText, SeverityNumber, ServiceName, Body, ScopeName, ScopeVersion, ResourceAttributes, LogAttributes';

    /**
     * A search term made only of these characters can use the token bloom filter.
     */
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_]{3,}$/';

    /**
     * The bucket widths, in seconds, the volume histogram may choose from.
     *
     * @var list<int>
     */
    private const BUCKET_INTERVALS = [1, 5, 15, 30, 60, 300, 900, 1800, 3600, 10800, 21600, 43200, 86400];

    /**
     * How many bars the histogram aims for across the selected window.
     */
    private const TARGET_BUCKETS = 48;

    /**
     * The hard ceiling on generated buckets, so an absurd window cannot blow up the payload.
     */
    private const MAX_BUCKETS = 240;

    public function __construct(private readonly ClickHouseClient $client) {}

    /**
     * Fetch a page of logs, newest first.
     *
     * @param  list<int>  $projectIds
     * @return LogResult
     */
    public function search(array $projectIds, LogFilters $filters): array
    {
        if ($projectIds === []) {
            return ['rows' => [], 'nextCursor' => null, 'unavailable' => false];
        }

        [$conditions, $params] = $this->conditions($projectIds, $filters);

        if ($filters->cursor !== null) {
            $conditions[] = 'Timestamp < {cursor:DateTime64(9)}';
            $params['cursor'] = $filters->cursor;
        }

        $params['rowLimit'] = $filters->limit;

        $rows = $this->run($conditions, $params);

        if ($rows === null) {
            return ['rows' => [], 'nextCursor' => null, 'unavailable' => true];
        }

        $nextCursor = count($rows) < $filters->limit
            ? null
            : ($rows[count($rows) - 1]['timestamp'] ?? null);

        return ['rows' => $rows, 'nextCursor' => $nextCursor, 'unavailable' => false];
    }

    /**
     * Fetch the logs recorded after the given timestamp, newest first.
     *
     * @param  list<int>  $projectIds
     * @return LogResult
     */
    public function tail(array $projectIds, LogFilters $filters, ?string $after): array
    {
        if ($projectIds === []) {
            return ['rows' => [], 'nextCursor' => null, 'unavailable' => false];
        }

        [$conditions, $params] = $this->conditions($projectIds, $filters, withTimeWindow: false);

        $conditions[] = 'Timestamp > {after:DateTime64(9)}';
        $params['after'] = $after ?? $filters->from->clone()->utc()->format('Y-m-d H:i:s.u');
        $params['rowLimit'] = $filters->limit;

        $rows = $this->run($conditions, $params);

        if ($rows === null) {
            return ['rows' => [], 'nextCursor' => null, 'unavailable' => true];
        }

        return ['rows' => $rows, 'nextCursor' => null, 'unavailable' => false];
    }

    /**
     * Whether any of the given projects has ever received a single log line.
     *
     * This is the cheapest question the viewer asks: it exists only to decide
     * whether to show onboarding, so it stops at the first matching row and
     * ignores every filter the user has set. A busy ClickHouse answers "yes",
     * because an overloaded database must never make an established team look
     * like a brand new one.
     *
     * @param  list<int>  $projectIds
     */
    public function hasAnyLogs(array $projectIds): bool
    {
        if ($projectIds === []) {
            return false;
        }

        $sql = 'SELECT 1 AS Present FROM otel_logs WHERE ProjectId IN {projectIds:Array(UInt64)} LIMIT 1';
        $params = ['projectIds' => '['.implode(',', $projectIds).']'];

        try {
            return $this->client->select($sql, $params) !== [];
        } catch (ClickHouseException $exception) {
            if (! $exception->isOverload()) {
                throw $exception;
            }

            report($exception);

            return true;
        }
    }

    /**
     * Count logs per time bucket and severity across the selected window.
     *
     * The bucket width is derived from the window on the server, so the chart
     * always gets a comparable number of bars whether the user is looking at
     * fifteen minutes or seven days. Empty buckets are filled in here rather
     * than in SQL so the time axis stays honest: a gap means no logs, not a
     * missing row.
     *
     * @param  list<int>  $projectIds
     * @return HistogramResult
     */
    public function histogram(array $projectIds, LogFilters $filters): array
    {
        $intervalSeconds = $this->bucketInterval($filters);

        if ($projectIds === []) {
            return $this->emptyHistogram($filters, $intervalSeconds);
        }

        [$conditions, $params] = $this->conditions($projectIds, $filters);

        $params['bucketSeconds'] = $intervalSeconds;

        $sql = sprintf(
            'SELECT toStartOfInterval(Timestamp, toIntervalSecond({bucketSeconds:UInt32})) AS Bucket, %s AS Level, count() AS Total '
            .'FROM otel_logs WHERE %s GROUP BY Bucket, Level ORDER BY Bucket ASC',
            $this->severityBucketExpression(),
            implode(' AND ', $conditions),
        );

        try {
            $rows = $this->client->select($sql, $params);
        } catch (ClickHouseException $exception) {
            if (! $exception->isOverload()) {
                throw $exception;
            }

            report($exception);

            $empty = $this->emptyHistogram($filters, $intervalSeconds);
            $empty['unavailable'] = true;

            return $empty;
        }

        /** @var array<int, array<string, int>> $counts */
        $counts = [];

        foreach ($rows as $row) {
            $bucket = (string) ($row['Bucket'] ?? '');
            $level = SeverityLevel::cases()[(int) ($row['Level'] ?? 2)] ?? SeverityLevel::Info;

            if ($bucket === '') {
                continue;
            }

            $key = Carbon::parse($bucket)->utc()->getTimestamp();
            $counts[$key][$level->value] = ($counts[$key][$level->value] ?? 0) + (int) ($row['Total'] ?? 0);
        }

        return $this->fillBuckets($filters, $intervalSeconds, $counts);
    }

    /**
     * The SQL expression bucketing an OTel severity number into a level index.
     *
     * A record with no severity number at all is counted as info, matching the
     * fallback the viewer applies when it cannot read a level off a row.
     */
    private function severityBucketExpression(): string
    {
        return 'multiIf(SeverityNumber >= 21, 5, SeverityNumber >= 17, 4, SeverityNumber >= 13, 3, SeverityNumber >= 9, 2, SeverityNumber >= 5, 1, SeverityNumber >= 1, 0, 2)';
    }

    /**
     * Choose the narrowest bucket width that keeps the bar count near the target.
     */
    private function bucketInterval(LogFilters $filters): int
    {
        $span = max(1, $filters->to->getTimestamp() - $filters->from->getTimestamp());

        foreach (self::BUCKET_INTERVALS as $interval) {
            if ((int) ceil($span / $interval) <= self::TARGET_BUCKETS) {
                return $interval;
            }
        }

        return (int) max(
            self::BUCKET_INTERVALS[count(self::BUCKET_INTERVALS) - 1],
            (int) ceil($span / self::MAX_BUCKETS),
        );
    }

    /**
     * Expand the sparse counts into one entry per bucket across the window.
     *
     * @param  array<int, array<string, int>>  $counts
     * @return HistogramResult
     */
    private function fillBuckets(LogFilters $filters, int $intervalSeconds, array $counts): array
    {
        $start = intdiv($filters->from->getTimestamp(), $intervalSeconds) * $intervalSeconds;
        $end = $filters->to->getTimestamp();

        $buckets = [];
        $total = 0;

        for ($at = $start; $at <= $end && count($buckets) < self::MAX_BUCKETS; $at += $intervalSeconds) {
            $bucketCounts = [];
            $bucketTotal = 0;

            foreach (SeverityLevel::cases() as $level) {
                $value = $counts[$at][$level->value] ?? 0;
                $bucketCounts[$level->value] = $value;
                $bucketTotal += $value;
            }

            $buckets[] = [
                'bucket' => Carbon::createFromTimestampUTC($at)->format('Y-m-d H:i:s.u'),
                'counts' => $bucketCounts,
                'total' => $bucketTotal,
            ];

            $total += $bucketTotal;
        }

        return [
            'buckets' => $buckets,
            'intervalSeconds' => $intervalSeconds,
            'total' => $total,
            'unavailable' => false,
        ];
    }

    /**
     * An all-zero histogram spanning the window.
     *
     * @return HistogramResult
     */
    private function emptyHistogram(LogFilters $filters, int $intervalSeconds): array
    {
        return $this->fillBuckets($filters, $intervalSeconds, []);
    }

    /**
     * Run the statement, returning null when ClickHouse is temporarily unavailable.
     *
     * @param  list<string>  $conditions
     * @param  array<string, scalar|null>  $params
     * @return list<LogRow>|null
     */
    private function run(array $conditions, array $params): ?array
    {
        $sql = sprintf(
            'SELECT %s FROM otel_logs WHERE %s ORDER BY Timestamp DESC LIMIT {rowLimit:UInt32}',
            self::COLUMNS,
            implode(' AND ', $conditions),
        );

        try {
            $rows = $this->client->select($sql, $params);
        } catch (ClickHouseException $exception) {
            if (! $exception->isOverload()) {
                throw $exception;
            }

            report($exception);

            return null;
        }

        return array_values(array_map($this->mapRow(...), $rows));
    }

    /**
     * Build the shared WHERE conditions and their bound parameters.
     *
     * @param  list<int>  $projectIds
     * @return array{0: list<string>, 1: array<string, scalar|null>}
     */
    private function conditions(array $projectIds, LogFilters $filters, bool $withTimeWindow = true): array
    {
        $conditions = ['ProjectId IN {projectIds:Array(UInt64)}'];
        $params = ['projectIds' => '['.implode(',', $projectIds).']'];

        if ($withTimeWindow) {
            $conditions[] = 'Timestamp >= {from:DateTime64(9)}';
            $conditions[] = 'Timestamp <= {to:DateTime64(9)}';
            $params['from'] = $this->formatTimestamp($filters->from);
            $params['to'] = $this->formatTimestamp($filters->to);
        }

        if ($filters->service !== null) {
            $conditions[] = 'ServiceName = {service:String}';
            $params['service'] = $filters->service;
        }

        if ($filters->severities !== []) {
            $ranges = [];

            foreach ($filters->severities as $index => $level) {
                $ranges[] = sprintf(
                    '(SeverityNumber >= {severityMin%d:UInt8} AND SeverityNumber <= {severityMax%d:UInt8})',
                    $index,
                    $index,
                );

                $params['severityMin'.$index] = $level->minimumSeverityNumber();
                $params['severityMax'.$index] = $level->maximumSeverityNumber();
            }

            $conditions[] = '('.implode(' OR ', $ranges).')';
        }

        if ($filters->search !== null) {
            if (preg_match(self::TOKEN_PATTERN, $filters->search) === 1) {
                $conditions[] = 'hasToken(Body, {search:String})';
                $params['search'] = $filters->search;
            } else {
                $conditions[] = 'Body ILIKE {search:String}';
                $params['search'] = '%'.$this->escapeLike($filters->search).'%';
            }
        }

        return [$conditions, $params];
    }

    /**
     * Render a timestamp the way ClickHouse expects a DateTime64 parameter.
     */
    private function formatTimestamp(Carbon $timestamp): string
    {
        return $timestamp->clone()->utc()->format('Y-m-d H:i:s.u');
    }

    /**
     * Escape the ILIKE wildcards so a search term is matched literally.
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }

    /**
     * Normalise a ClickHouse row into the shape the frontend consumes.
     *
     * @param  array<string, mixed>  $row
     * @return LogRow
     */
    private function mapRow(array $row): array
    {
        /** @var array<string, string> $resourceAttributes */
        $resourceAttributes = is_array($row['ResourceAttributes'] ?? null) ? $row['ResourceAttributes'] : [];
        /** @var array<string, string> $logAttributes */
        $logAttributes = is_array($row['LogAttributes'] ?? null) ? $row['LogAttributes'] : [];

        return [
            'projectId' => (int) ($row['ProjectId'] ?? 0),
            'timestamp' => (string) ($row['Timestamp'] ?? ''),
            'traceId' => (string) ($row['TraceId'] ?? ''),
            'spanId' => (string) ($row['SpanId'] ?? ''),
            'severityText' => (string) ($row['SeverityText'] ?? ''),
            'severityNumber' => (int) ($row['SeverityNumber'] ?? 0),
            'serviceName' => (string) ($row['ServiceName'] ?? ''),
            'body' => (string) ($row['Body'] ?? ''),
            'scopeName' => (string) ($row['ScopeName'] ?? ''),
            'scopeVersion' => (string) ($row['ScopeVersion'] ?? ''),
            'resourceAttributes' => $resourceAttributes,
            'logAttributes' => $logAttributes,
        ];
    }
}
