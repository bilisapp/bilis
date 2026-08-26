<?php

namespace App\Services\Logs;

use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;

/**
 * Answers "is my system healthy?" for the dashboard's daily digest.
 *
 * A handful of questions, one statement each, all scoped to the server-resolved
 * project id list and all shaped the way SCHEMA.md R4 requires: a plain
 * `ProjectId IN … AND Timestamp >= … AND Timestamp <= …` on the raw column,
 * never a bucket expression. `conditions()` is the single builder for that base
 * predicate; the aggregate clauses append to it. Bucketing for the hourly
 * series lives in SELECT/GROUP BY, where R4 has nothing to say about it.
 *
 * The comparison window is deliberately a single 48 hour scan split by a
 * `countIf` on the midpoint rather than two 24 hour queries — one seek over the
 * sort key answers both halves.
 *
 * @phpstan-type DigestCounts array{current: int, previous: int}
 * @phpstan-type DigestError array{body: string, total: int}
 * @phpstan-type DigestService array{name: string, lastSeen: string, quiet: bool, series: list<int>}
 * @phpstan-type DigestPoint array{bucket: string, total: int, errors: int}
 * @phpstan-type DigestResult array{logs: DigestCounts, errors: DigestCounts, topErrors: list<DigestError>, services: list<DigestService>, series: list<DigestPoint>, generatedAt: string, unavailable: bool}
 */
class LogDigest
{
    /**
     * How long a computed digest is reused.
     *
     * The dashboard is the first page of every session, so the 48 hour scan
     * must not run per view. Two minutes is well inside the resolution of a
     * "last 24 hours" number.
     */
    private const CACHE_SECONDS = 120;

    /**
     * The OTel severity number at which a record counts as an error.
     *
     * 17 is ERROR; everything above it (ERROR2-4, FATAL1-4) is worse.
     */
    private const ERROR_SEVERITY_NUMBER = 17;

    /**
     * How many recurring error bodies the digest lists.
     */
    private const TOP_ERROR_LIMIT = 3;

    /**
     * How much of an error body survives into the prop.
     *
     * Truncated in PHP rather than in SQL so the GROUP BY still keys on the
     * whole body — cutting first would merge distinct errors that share a
     * prefix.
     */
    private const BODY_LENGTH = 160;

    /**
     * How far back the liveness list looks for a service.
     *
     * Matches LogQuery's service lookback: a shipper that died yesterday still
     * belongs in the list, which is the whole point of the "quiet" flag.
     */
    private const SERVICE_LOOKBACK_DAYS = 7;

    /**
     * The most services the digest will list.
     */
    private const SERVICE_LIMIT = 50;

    /**
     * How long a service may stay silent before it is flagged as quiet.
     */
    private const QUIET_MINUTES = 60;

    /**
     * How many hourly points the sparkline series carries.
     *
     * The window is snapped to whole hours so the series is always exactly
     * this long: the oldest point is the top of the hour 23 hours ago, the
     * newest is the current (still filling) hour.
     */
    private const SERIES_HOURS = 24;

    public function __construct(
        private readonly ClickHouseClient $client,
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Summarise the last 24 hours for the given projects.
     *
     * An overloaded ClickHouse yields `unavailable: true` with zeroes — never
     * cached, so the section recovers on the next visit rather than showing a
     * stale outage for the whole cache window.
     *
     * @param  list<string>  $projectIds
     * @return DigestResult
     */
    public function overview(array $projectIds): array
    {
        if ($projectIds === []) {
            return $this->emptyResult(false);
        }

        /*
         * The version prefix is part of the key on purpose: the shape stored
         * here has grown a field, and a warm cache from the previous shape
         * must never be served back as a digest missing `series` — or, at
         * v3, missing the `generatedAt` the page states its age from, or, at
         * v4, missing the per-service trend each liveness row draws.
         */
        $key = 'logs.digest.v4.'.sha1(implode(',', $projectIds));

        /** @var DigestResult|null $cached */
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $result = $this->compute($projectIds);
        } catch (ClickHouseException $exception) {
            if (! $exception->isOverload()) {
                throw $exception;
            }

            report($exception);

            return $this->emptyResult(true);
        }

        $this->cache->put($key, $result, self::CACHE_SECONDS);

        return $result;
    }

    /**
     * Run the statements that make up the digest.
     *
     * @param  list<string>  $projectIds
     * @return DigestResult
     */
    private function compute(array $projectIds): array
    {
        /*
         * Snapped to the minute so the same 24 hour window keeps the same
         * boundaries — and therefore the same answer — for a whole minute,
         * which is what makes the cache worth having.
         */
        $now = Carbon::now()->utc()->startOfMinute();

        $counts = $this->counts($projectIds, $now);

        return [
            'logs' => $counts['logs'],
            'errors' => $counts['errors'],
            'topErrors' => $this->topErrors($projectIds, $now),
            'services' => $this->services($projectIds, $now, $this->serviceSeries($projectIds, $now)),
            'series' => $this->series($projectIds, $now),
            /*
             * Measured inside the cached payload on purpose: a digest served
             * from cache has to report the age of the numbers it is showing,
             * not the age of the request that happened to read it.
             */
            'generatedAt' => $this->formatTimestamp($now),
            'unavailable' => false,
        ];
    }

    /**
     * Volume and error counts for the last 24 hours and the 24 before it.
     *
     * @param  list<string>  $projectIds
     * @return array{logs: DigestCounts, errors: DigestCounts}
     */
    private function counts(array $projectIds, Carbon $now): array
    {
        [$conditions, $params] = $this->conditions($projectIds, $now->clone()->subHours(48), $now);

        $params['mid'] = $this->formatTimestamp($now->clone()->subHours(24));
        $params['errorSeverity'] = self::ERROR_SEVERITY_NUMBER;

        $sql = sprintf(
            'SELECT count() AS Total, '
            .'countIf(Timestamp >= {mid:DateTime64(9)}) AS Current, '
            .'countIf(SeverityNumber >= {errorSeverity:UInt8}) AS ErrorTotal, '
            .'countIf(SeverityNumber >= {errorSeverity:UInt8} AND Timestamp >= {mid:DateTime64(9)}) AS ErrorCurrent '
            .'FROM otel_logs WHERE %s',
            implode(' AND ', $conditions),
        );

        $row = $this->client->select($sql, $params)[0] ?? [];

        $total = (int) ($row['Total'] ?? 0);
        $current = (int) ($row['Current'] ?? 0);
        $errorTotal = (int) ($row['ErrorTotal'] ?? 0);
        $errorCurrent = (int) ($row['ErrorCurrent'] ?? 0);

        return [
            'logs' => ['current' => $current, 'previous' => max(0, $total - $current)],
            'errors' => ['current' => $errorCurrent, 'previous' => max(0, $errorTotal - $errorCurrent)],
        ];
    }

    /**
     * The error bodies seen most often in the last 24 hours.
     *
     * @param  list<string>  $projectIds
     * @return list<DigestError>
     */
    private function topErrors(array $projectIds, Carbon $now): array
    {
        [$conditions, $params] = $this->conditions($projectIds, $now->clone()->subHours(24), $now);

        $conditions[] = 'SeverityNumber >= {errorSeverity:UInt8}';
        $conditions[] = "Body != ''";
        $params['errorSeverity'] = self::ERROR_SEVERITY_NUMBER;
        $params['rowLimit'] = self::TOP_ERROR_LIMIT;

        $sql = sprintf(
            'SELECT Body, count() AS Total FROM otel_logs WHERE %s '
            .'GROUP BY Body ORDER BY Total DESC LIMIT {rowLimit:UInt32}',
            implode(' AND ', $conditions),
        );

        $errors = [];

        foreach ($this->client->select($sql, $params) as $row) {
            $body = (string) ($row['Body'] ?? '');

            if ($body === '') {
                continue;
            }

            $errors[] = [
                'body' => mb_strimwidth($body, 0, self::BODY_LENGTH, '…'),
                'total' => (int) ($row['Total'] ?? 0),
            ];
        }

        return $errors;
    }

    /**
     * When each service last logged, quietest first.
     *
     * This is the "it went quiet" signal: a shipper that dies stops producing
     * rows rather than producing error rows, so nothing else on the page would
     * notice it.
     *
     * @param  list<string>  $projectIds
     * @param  array<string, list<int>>  $series  The 24 hour trend per service, from serviceSeries().
     * @return list<DigestService>
     */
    private function services(array $projectIds, Carbon $now, array $series): array
    {
        [$conditions, $params] = $this->conditions(
            $projectIds,
            $now->clone()->subDays(self::SERVICE_LOOKBACK_DAYS),
            $now,
        );

        $conditions[] = "ServiceName != ''";
        $params['rowLimit'] = self::SERVICE_LIMIT;

        $sql = sprintf(
            'SELECT ServiceName, max(Timestamp) AS LastSeen FROM otel_logs WHERE %s '
            .'GROUP BY ServiceName ORDER BY LastSeen ASC LIMIT {rowLimit:UInt32}',
            implode(' AND ', $conditions),
        );

        $quietBefore = $now->clone()->subMinutes(self::QUIET_MINUTES);

        /*
         * A service in the 7 day liveness list that logged nothing in the
         * last 24 hours is the whole point of the trend: it draws a flatline,
         * so the row has to carry zeroes rather than be left without a series.
         */
        $flat = $this->fillHours([], $this->seriesStart($now));

        $services = [];

        foreach ($this->client->select($sql, $params) as $row) {
            $name = (string) ($row['ServiceName'] ?? '');
            $lastSeen = (string) ($row['LastSeen'] ?? '');

            if ($name === '' || $lastSeen === '') {
                continue;
            }

            $services[] = [
                'name' => $name,
                'lastSeen' => $lastSeen,
                'quiet' => Carbon::parse($lastSeen, 'UTC')->lessThan($quietBefore),
                'series' => $series[$name] ?? $flat,
            ];
        }

        return $services;
    }

    /**
     * Hourly volume and error counts across the last 24 hours, oldest first.
     *
     * The sparkline behind the two headline numbers. `toStartOfInterval` is a
     * SELECT/GROUP BY expression here, never a bound — R4 wants the WHERE to
     * stay a plain range on the raw Timestamp, which is exactly what
     * `conditions()` hands back.
     *
     * Missing hours are filled in PHP rather than left out (the same
     * philosophy as LogQuery::fillBuckets): a gap means zero, and dropping it
     * would silently compress the axis and lie about the shape of the day.
     *
     * @param  list<string>  $projectIds
     * @return list<DigestPoint>
     */
    private function series(array $projectIds, Carbon $now): array
    {
        $start = $this->seriesStart($now);

        [$conditions, $params] = $this->conditions($projectIds, $start, $now);

        $params['errorSeverity'] = self::ERROR_SEVERITY_NUMBER;

        $sql = sprintf(
            'SELECT toStartOfInterval(Timestamp, toIntervalHour(1)) AS Bucket, count() AS Total, '
            .'countIf(SeverityNumber >= {errorSeverity:UInt8}) AS Errors '
            .'FROM otel_logs WHERE %s GROUP BY Bucket ORDER BY Bucket ASC',
            implode(' AND ', $conditions),
        );

        /** @var array<int, array{total: int, errors: int}> $counts */
        $counts = [];

        foreach ($this->client->select($sql, $params) as $row) {
            $bucket = (string) ($row['Bucket'] ?? '');

            if ($bucket === '') {
                continue;
            }

            $at = Carbon::parse($bucket, 'UTC')->getTimestamp();

            $counts[$at] = [
                'total' => (int) ($row['Total'] ?? 0),
                'errors' => (int) ($row['Errors'] ?? 0),
            ];
        }

        $points = [];

        for ($hour = 0; $hour < self::SERIES_HOURS; $hour++) {
            $at = $start->clone()->addHours($hour);
            $counted = $counts[$at->getTimestamp()] ?? ['total' => 0, 'errors' => 0];

            $points[] = [
                'bucket' => $this->formatTimestamp($at),
                'total' => $counted['total'],
                'errors' => $counted['errors'],
            ];
        }

        return $points;
    }

    /**
     * The same 24 hourly buckets, split by service.
     *
     * A second aggregate over the window `series()` already reads rather than
     * a fan-out of one query per service: one scan answers every row of the
     * liveness list. `toStartOfInterval` lives in SELECT/GROUP BY only — the
     * WHERE stays the plain range `conditions()` builds (R4).
     *
     * @param  list<string>  $projectIds
     * @return array<string, list<int>>
     */
    private function serviceSeries(array $projectIds, Carbon $now): array
    {
        $start = $this->seriesStart($now);

        [$conditions, $params] = $this->conditions($projectIds, $start, $now);

        $conditions[] = "ServiceName != ''";

        $sql = sprintf(
            'SELECT ServiceName, toStartOfInterval(Timestamp, toIntervalHour(1)) AS Bucket, '
            .'count() AS Total FROM otel_logs WHERE %s '
            .'GROUP BY ServiceName, Bucket ORDER BY Bucket ASC',
            implode(' AND ', $conditions),
        );

        /** @var array<string, array<int, int>> $counts */
        $counts = [];

        foreach ($this->client->select($sql, $params) as $row) {
            $name = (string) ($row['ServiceName'] ?? '');
            $bucket = (string) ($row['Bucket'] ?? '');

            if ($name === '' || $bucket === '') {
                continue;
            }

            $counts[$name][Carbon::parse($bucket, 'UTC')->getTimestamp()] = (int) ($row['Total'] ?? 0);
        }

        $series = [];

        foreach ($counts as $name => $byBucket) {
            $series[$name] = $this->fillHours($byBucket, $start);
        }

        return $series;
    }

    /**
     * The top of the oldest hour the 24 hour trend covers.
     *
     * Snapped so the series is always exactly SERIES_HOURS long and every
     * trend on the page — the tiles' and the per-service ones — shares the
     * same buckets, which is what lets the service rows ship bare totals.
     */
    private function seriesStart(Carbon $now): Carbon
    {
        return $now->clone()->startOfHour()->subHours(self::SERIES_HOURS - 1);
    }

    /**
     * Spread bucket-keyed counts across the dense 24 hour window.
     *
     * A missing hour is a zero, never a dropped point: leaving it out would
     * compress the axis and lie about the shape of the day.
     *
     * @param  array<int, int>  $counts  Keyed by the bucket's unix timestamp.
     * @return list<int>
     */
    private function fillHours(array $counts, Carbon $start): array
    {
        $points = [];

        for ($hour = 0; $hour < self::SERIES_HOURS; $hour++) {
            $points[] = $counts[$start->clone()->addHours($hour)->getTimestamp()] ?? 0;
        }

        return $points;
    }

    /**
     * Build the shared WHERE conditions and their bound parameters.
     *
     * The one place the base predicate is assembled (R4): a plain range on the
     * raw Timestamp behind the ProjectId prefix. Callers append; nothing drops
     * the ProjectId predicate it starts with.
     *
     * @param  list<string>  $projectIds
     * @return array{0: list<string>, 1: array<string, scalar|null>}
     */
    private function conditions(array $projectIds, Carbon $from, Carbon $to): array
    {
        return [
            [
                'ProjectId IN {projectIds:Array(String)}',
                'Timestamp >= {from:DateTime64(9)}',
                'Timestamp <= {to:DateTime64(9)}',
            ],
            [
                'projectIds' => $this->projectIdsParameter($projectIds),
                'from' => $this->formatTimestamp($from),
                'to' => $this->formatTimestamp($to),
            ],
        ];
    }

    /**
     * A digest with nothing in it.
     *
     * @return DigestResult
     */
    private function emptyResult(bool $unavailable): array
    {
        return [
            'logs' => ['current' => 0, 'previous' => 0],
            'errors' => ['current' => 0, 'previous' => 0],
            'topErrors' => [],
            'services' => [],
            'series' => $this->emptySeries(),
            'generatedAt' => $this->formatTimestamp(Carbon::now()->utc()),
            'unavailable' => $unavailable,
        ];
    }

    /**
     * A dense all-zero series spanning the same 24 snapped hours.
     *
     * An unavailable digest still renders a sparkline — a flat baseline, not a
     * missing element that would reflow the tile.
     *
     * @return list<DigestPoint>
     */
    private function emptySeries(): array
    {
        $start = Carbon::now()->utc()->startOfHour()->subHours(self::SERIES_HOURS - 1);

        $points = [];

        for ($hour = 0; $hour < self::SERIES_HOURS; $hour++) {
            $points[] = [
                'bucket' => $this->formatTimestamp($start->clone()->addHours($hour)),
                'total' => 0,
                'errors' => 0,
            ];
        }

        return $points;
    }

    /**
     * Render a project id list the way ClickHouse expects an Array(String) parameter.
     *
     * @param  list<string>  $projectIds
     */
    private function projectIdsParameter(array $projectIds): string
    {
        $quoted = array_map(
            fn (string $id): string => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $id)."'",
            $projectIds,
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
}
