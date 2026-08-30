<?php

namespace App\Services\Logs;

use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;

/**
 * Builds and runs the parameterized ClickHouse statements behind the log viewer.
 *
 * Every statement is constrained to an explicit list of project ids resolved on
 * the server, and every user supplied value is bound as a ClickHouse query
 * parameter rather than being interpolated into the SQL.
 *
 * The sort key is (ProjectId, Timestamp, ServiceName), so every statement reads
 * in Timestamp order behind the ProjectId prefix and the range predicate is a
 * plain one on raw Timestamp. `conditions()` is the single builder for that base
 * predicate — see rule R4 in database/clickhouse/SCHEMA.md. User filters append
 * to it; they never replace the ProjectId predicate.
 *
 * @phpstan-type LogRow array{timestamp: string, traceId: string, spanId: string, severityText: string, severityNumber: int, serviceName: string, body: string, scopeName: string, scopeVersion: string, resourceAttributes: array<string, string>, logAttributes: array<string, string>, projectId: string}
 * @phpstan-type LogResult array{rows: list<LogRow>, nextCursor: string|null, unavailable: bool}
 * @phpstan-type ErrorSampleResult array{rows: list<LogRow>, unavailable: bool}
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
     * A search term made only of these characters is a single whole token.
     *
     * The index is built on lower(Body), so the query expression must be
     * hasAnyTokens(lower(Body), [lower(...)]) — see SCHEMA.md R5.
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

    /**
     * How many error rows one autofix scan reads per project by default.
     */
    private const ERROR_SAMPLE_LIMIT = 500;

    /**
     * The hard ceiling on an autofix scan, whatever the caller asks for.
     */
    private const ERROR_SAMPLE_MAX = 5000;

    /**
     * How many error rows one verification pass reads for a merged fix.
     *
     * Wider than a scan's default: the window runs from the merge to now, so
     * it can span days rather than the scan's hour.
     */
    private const ERROR_OCCURRENCE_LIMIT = 2000;

    /**
     * How far back the service list looks when the selected window is shorter.
     *
     * The list answers "what does this project run?", which a fifteen minute
     * window cannot: a service that logged an hour ago still belongs in it.
     */
    private const SERVICE_LOOKBACK_DAYS = 7;

    /**
     * The most service names the picker will ever be handed.
     */
    private const SERVICE_LIMIT = 200;

    /**
     * How long a resolved service list is reused.
     *
     * Every filter change is a fresh visit, so without this the picker would
     * re-run a DISTINCT scan on every keystroke. A service that starts logging
     * shows up a minute late, which is the right trade for a list of names.
     */
    private const SERVICE_CACHE_SECONDS = 60;

    public function __construct(
        private readonly ClickHouseClient $client,
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Fetch a page of logs, newest first.
     *
     * @param  list<string>  $projectIds
     * @return LogResult
     */
    public function search(array $projectIds, LogFilters $filters): array
    {
        if ($projectIds === []) {
            return ['rows' => [], 'nextCursor' => null, 'unavailable' => false];
        }

        [$conditions, $params] = $this->conditions($projectIds, $filters);

        if ($filters->cursor !== null) {
            /*
             * Timestamp sits directly behind ProjectId in the sort key and the
             * page is ordered by it, so a strict bound on the last row's
             * timestamp is both the correct cut and an index seek.
             */
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
     * @param  list<string>  $projectIds
     * @return LogResult
     */
    public function tail(array $projectIds, LogFilters $filters, ?string $after): array
    {
        if ($projectIds === []) {
            return ['rows' => [], 'nextCursor' => null, 'unavailable' => false];
        }

        [$conditions, $params] = $this->conditions($projectIds, $filters, withTimeWindow: false);

        /*
         * Live tail drops the window entirely (R4) and keeps only this lower
         * bound, which the sort key satisfies by reading in order from the
         * cursor forward.
         */
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
     * The distinct service names seen for the given projects, alphabetically.
     *
     * This feeds the service picker, so it deliberately ignores the service,
     * severity and search filters — narrowing to one service must not collapse
     * the list of services you can switch to. The window is the selected one
     * widened to at least the last week, because "which services do we have"
     * is a question about the project, not about the current fifteen minutes.
     *
     * An overloaded ClickHouse yields an empty list: the picker degrades to
     * "all services" rather than taking the page down with it.
     *
     * @param  list<string>  $projectIds
     * @return list<string>
     */
    public function services(array $projectIds, LogFilters $filters): array
    {
        if ($projectIds === []) {
            return [];
        }

        [$conditions, $params] = $this->conditions(
            $projectIds,
            $filters,
            withTimeWindow: false,
            withUserFilters: false,
        );

        /*
         * Both bounds are snapped to the minute so the same window keeps the
         * same cache key for a whole minute — a "last 7 days" lower bound that
         * moved with every request would never hit the cache.
         */
        $now = Carbon::now();
        $from = $filters->from->clone()->min($now->clone()->subDays(self::SERVICE_LOOKBACK_DAYS))->startOfMinute();
        $to = $filters->to->clone()->max($now)->startOfMinute()->addMinute();

        $conditions[] = 'Timestamp >= {from:DateTime64(9)}';
        $conditions[] = 'Timestamp <= {to:DateTime64(9)}';
        $conditions[] = "ServiceName != ''";
        $params['from'] = $this->formatTimestamp($from);
        $params['to'] = $this->formatTimestamp($to);
        $params['rowLimit'] = self::SERVICE_LIMIT;

        $sql = sprintf(
            'SELECT DISTINCT ServiceName FROM otel_logs WHERE %s ORDER BY ServiceName ASC LIMIT {rowLimit:UInt32}',
            implode(' AND ', $conditions),
        );

        $key = 'logs.services.'.sha1(implode(',', $projectIds).'|'.$params['from'].'|'.$params['to']);

        /** @var list<string> $services */
        $services = $this->cache->remember(
            $key,
            self::SERVICE_CACHE_SECONDS,
            function () use ($sql, $params): array {
                try {
                    $rows = $this->client->select($sql, $params);
                } catch (ClickHouseException $exception) {
                    if (! $exception->isOverload()) {
                        throw $exception;
                    }

                    report($exception);

                    return [];
                }

                return array_values(array_filter(array_map(
                    fn (array $row): string => (string) ($row['ServiceName'] ?? ''),
                    $rows,
                ), fn (string $name): bool => $name !== ''));
            },
        );

        return $services;
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
     * @param  list<string>  $projectIds
     */
    public function hasAnyLogs(array $projectIds): bool
    {
        if ($projectIds === []) {
            return false;
        }

        /*
         * Deliberately unconstrained in time: the question is "ever", so a full
         * scan is the point, and it stops at the first matching row. The rows it
         * may read are limited by the bound project id list, which the server
         * resolved — not by the sort key, which is only clustering (SCHEMA.md R3).
         */
        $sql = 'SELECT 1 AS Present FROM otel_logs WHERE ProjectId IN {projectIds:Array(String)} LIMIT 1';
        $params = ['projectIds' => $this->projectIdsParameter($projectIds)];

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
     * Fetch recent error rows for the autofix trigger, newest first.
     *
     * The autofix scan needs raw error records rather than a page of the
     * viewer, but it reads the same table under the same rules: the base
     * predicate still comes from `conditions()` (R4) — ProjectId IN plus a
     * plain range on raw Timestamp, ordered by Timestamp DESC — and the only
     * thing appended is a lower bound on SeverityNumber. Nothing here replaces
     * or weakens the ProjectId predicate.
     *
     * The user filters are deliberately off: this is a background scan, not a
     * search, so there is no severity or search term to honour. Services are
     * the exception, and not a user filter at all — a project can ship several
     * services from several repositories, and each repository must be scanned
     * for its own services only. Filtering here rather than in PHP also keeps
     * the row sample honest: 500 rows of a noisy neighbour would otherwise
     * crowd out every error the repository is actually responsible for.
     *
     * `services` reads only those names; `excludeServices` reads everything
     * else, which is how the catch-all repository of a project is scanned
     * without raising a second job for an error a sibling already owns. They
     * are ServiceName predicates appended to the base predicate (R4), never a
     * replacement for it.
     *
     * An overloaded ClickHouse yields `unavailable`, so the scan can skip this
     * pass instead of raising fix jobs from a half-read window.
     *
     * @param  list<string>  $projectIds
     * @param  list<string>|null  $services  read only these; null reads every service
     * @param  list<string>  $excludeServices  read everything but these
     * @return ErrorSampleResult
     */
    public function errorSamples(
        array $projectIds,
        Carbon $from,
        Carbon $to,
        int $limit = self::ERROR_SAMPLE_LIMIT,
        SeverityLevel $minimumSeverity = SeverityLevel::Error,
        ?array $services = null,
        array $excludeServices = [],
    ): array {
        if ($projectIds === []) {
            return ['rows' => [], 'unavailable' => false];
        }

        /*
         * An empty include list is "this repository claims nothing", which can
         * only ever match nothing. Answering it with a query would read the
         * whole window and throw the rows away.
         */
        if ($services !== null && $services === []) {
            return ['rows' => [], 'unavailable' => false];
        }

        [$conditions, $params] = $this->conditions(
            $projectIds,
            new LogFilters(from: $from, to: $to),
            withUserFilters: false,
        );

        /*
         * A lower bound rather than the viewer's per-bucket ranges: everything
         * at least as severe as the given level is a candidate, fatal included.
         */
        $conditions[] = 'SeverityNumber >= {severityFloor:UInt8}';
        $params['severityFloor'] = $minimumSeverity->minimumSeverityNumber();
        $params['rowLimit'] = max(1, min($limit, self::ERROR_SAMPLE_MAX));

        if ($services !== null) {
            $conditions[] = 'ServiceName IN {services:Array(String)}';
            $params['services'] = $this->stringArrayParameter($services);
        }

        if ($excludeServices !== []) {
            $conditions[] = 'ServiceName NOT IN {excludeServices:Array(String)}';
            $params['excludeServices'] = $this->stringArrayParameter($excludeServices);
        }

        $rows = $this->run($conditions, $params);

        if ($rows === null) {
            return ['rows' => [], 'unavailable' => true];
        }

        return ['rows' => $rows, 'unavailable' => false];
    }

    /**
     * Fetch a single project's error rows for the autofix verification loop.
     *
     * The verification pass asks a narrower question than the scan: did *this*
     * fingerprint happen again since the fix merged? The fingerprint itself is
     * computed in PHP, not in SQL, so what this returns is the raw candidate
     * rows for the window; the caller re-fingerprints them and counts the ones
     * that match.
     *
     * The read is the same contract as every other one (R4): the base
     * predicate comes from `conditions()` — ProjectId IN plus a plain range on
     * raw Timestamp, ordered by Timestamp DESC — and only a lower bound on
     * SeverityNumber is appended. The window can be days rather than the
     * scan's hour, so the row cap is higher, and still hard-capped.
     *
     * An overloaded ClickHouse yields `unavailable`, so the pass can be
     * skipped rather than declaring a fix verified off a half-read window.
     *
     * @return ErrorSampleResult
     */
    public function errorOccurrences(
        string $projectId,
        Carbon $from,
        Carbon $to,
        int $limit = self::ERROR_OCCURRENCE_LIMIT,
        SeverityLevel $minimumSeverity = SeverityLevel::Error,
    ): array {
        if ($projectId === '') {
            return ['rows' => [], 'unavailable' => false];
        }

        [$conditions, $params] = $this->conditions(
            [$projectId],
            new LogFilters(from: $from, to: $to),
            withUserFilters: false,
        );

        $conditions[] = 'SeverityNumber >= {severityFloor:UInt8}';
        $params['severityFloor'] = $minimumSeverity->minimumSeverityNumber();
        $params['rowLimit'] = max(1, min($limit, self::ERROR_SAMPLE_MAX));

        $rows = $this->run($conditions, $params);

        if ($rows === null) {
            return ['rows' => [], 'unavailable' => true];
        }

        return ['rows' => $rows, 'unavailable' => false];
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
     * @param  list<string>  $projectIds
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
     * This is the one place the base predicate is assembled (R4). Callers append
     * their own conditions to what comes back; nothing may drop the ProjectId
     * predicate it starts with.
     *
     * @param  list<string>  $projectIds
     * @param  bool  $withUserFilters  whether the service, severity and search filters apply
     * @return array{0: list<string>, 1: array<string, scalar|null>}
     */
    private function conditions(array $projectIds, LogFilters $filters, bool $withTimeWindow = true, bool $withUserFilters = true): array
    {
        $conditions = ['ProjectId IN {projectIds:Array(String)}'];
        $params = ['projectIds' => $this->projectIdsParameter($projectIds)];

        if ($withTimeWindow) {
            /*
             * A plain range on raw Timestamp: it is the second sort key column,
             * so this is the seek. No bucket expression is involved — if one is
             * ever reintroduced, R4 requires constraining it *and* the raw
             * column, with the bound itself truncated.
             */
            $conditions[] = 'Timestamp >= {from:DateTime64(9)}';
            $conditions[] = 'Timestamp <= {to:DateTime64(9)}';
            $params['from'] = $this->formatTimestamp($filters->from);
            $params['to'] = $this->formatTimestamp($filters->to);
        }

        if (! $withUserFilters) {
            return [$conditions, $params];
        }

        if ($filters->service !== null) {
            $conditions[] = 'ServiceName = {service:String}';
            $params['service'] = $filters->service;
        }

        /*
         * The trace side of the logs/traces link. TraceId carries a bloom filter
         * index, so this is a cheap narrowing on top of the ProjectId and time
         * predicates rather than a replacement for either — the time window the
         * caller came in with still applies, which is what keeps a trace's log
         * lookup bounded to the seconds around the trace.
         */
        if ($filters->traceId !== null) {
            $conditions[] = 'TraceId = {traceId:String}';
            $params['traceId'] = $filters->traceId;
        }

        if ($filters->spanId !== null) {
            $conditions[] = 'SpanId = {spanId:String}';
            $params['spanId'] = $filters->spanId;
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
                /*
                 * The expression has to be character for character the one the
                 * idx_lower_body index is defined on, or the index is skipped.
                 * The tokenizer splits but does not fold case, so both sides go
                 * through lower() — dropping the wrapper here would silently
                 * stop matching every line whose case differs from the term.
                 */
                $conditions[] = 'hasAnyTokens(lower(Body), [lower({search:String})])';
                $params['search'] = $filters->search;
            } else {
                /*
                 * The "contains" mode for terms that are not a single token: a
                 * real substring match rather than a token match. It is written
                 * against lower(Body) rather than as `Body ILIKE` because that
                 * is the indexed expression — ClickHouse can prune granules for
                 * a LIKE over a text index, and measurably does here, while the
                 * ILIKE form reads none of it. lower() + LIKE and ILIKE fold
                 * case identically (both ASCII only), so results are unchanged.
                 */
                $conditions[] = 'lower(Body) LIKE lower({search:String})';
                $params['search'] = '%'.$this->escapeLike($filters->search).'%';
            }
        }

        return [$conditions, $params];
    }

    /**
     * Render the project ids the way ClickHouse expects an Array(String) parameter.
     *
     * ProjectId is a String column, so the ids have to be quoted inside the
     * array literal. They are our own primary keys rather than user input, but
     * they are escaped anyway so this stays safe if that ever changes.
     *
     * @param  list<string>  $projectIds
     */
    private function projectIdsParameter(array $projectIds): string
    {
        return $this->stringArrayParameter($projectIds);
    }

    /**
     * Render a list of strings the way ClickHouse expects an Array(String) parameter.
     *
     * Service names come from customer telemetry rather than from our own
     * tables, so the escaping here is load-bearing rather than defensive: a
     * service called `a'b` must be matched, not executed.
     *
     * @param  list<string>  $values
     */
    private function stringArrayParameter(array $values): string
    {
        $quoted = array_map(
            fn (string $value): string => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'",
            $values,
        );

        return '['.implode(',', $quoted).']';
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
            'projectId' => (string) ($row['ProjectId'] ?? ''),
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
