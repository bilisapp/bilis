<?php

namespace App\Services\Issues;

use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use App\Services\ClickHouse\ClickHouseParameters;
use App\Services\Support\TimeBuckets;
use Illuminate\Support\Carbon;

/**
 * Every statement against the two issue tables.
 *
 * `issue_summary` is keyed (ProjectId, IssueFingerprint) and holds an issue's
 * all-time headline; `issue_hourly` is keyed (ProjectId, Hour,
 * IssueFingerprint) and holds per-hour counts. Both are AggregatingMergeTrees,
 * so every read re-aggregates by its key (SCHEMA.md R17), and every aggregate
 * is projected under a name that is not a column of the table — `sum(Events)
 * AS Events` would make a later `sum(Events)` resolve to `sum(sum(Events))`
 * and raise ILLEGAL_AGGREGATION, the trap R11 records for traces.
 *
 * The list follows R18: fingerprints are nominated from `issue_hourly` for
 * the window (a key-pruned range by the hour), then their headlines are a
 * point lookup on `issue_summary`.
 *
 * Project ids are always the server-resolved list, never a slug, and every
 * value is a bound parameter. An overloaded ClickHouse answers `null` so the
 * page can say so instead of failing.
 *
 * @phpstan-type IssueCandidate array{projectId: string, fingerprint: string, windowEvents: int, windowUsers: int, series: array<int, int>}
 * @phpstan-type IssueSummary array{projectId: string, fingerprint: string, type: string, message: string, origin: string, service: string, services: list<string>, environments: list<string>, firstSeen: int, lastSeen: int, lastIngested: int, events: int, users: int, firstRelease: string, lastRelease: string}
 * @phpstan-type AlertCandidate array{projectId: string, fingerprint: string, firstSeen: int, lastIngested: int, events: int, type: string, message: string, service: string}
 * @phpstan-type IssueHistogramBucket array{bucket: int, events: int, users: int}
 * @phpstan-type IssueHistogram array{buckets: list<IssueHistogramBucket>, intervalSeconds: int, unavailable: bool}
 */
class IssueQuery
{
    /**
     * The most issues one window nominates.
     *
     * Status, environment and search are judged in PHP over this set, so it
     * bounds the work a list view can do. Ordered by window events, so what a
     * cap drops is the long tail of one-off exceptions; the page says when it
     * happened.
     */
    public const CANDIDATE_LIMIT = 1000;

    /**
     * How many bars the issue page's histogram aims for.
     */
    private const TARGET_BUCKETS = 48;

    /**
     * How many headlines one lookup asks for at once.
     */
    private const SUMMARY_CHUNK = 500;

    public function __construct(private readonly ClickHouseClient $client) {}

    /**
     * The issues that occurred in the window, with their window counts.
     *
     * `series` maps each hour (Unix seconds) the issue occurred in to its
     * event count; hours with none are absent.
     *
     * @param  list<string>  $projectIds
     * @return list<IssueCandidate>|null
     */
    public function candidates(array $projectIds, Carbon $from, Carbon $to): ?array
    {
        if ($projectIds === []) {
            return [];
        }

        $rows = $this->select(
            <<<'SQL'
            SELECT
                ProjectId,
                IssueFingerprint,
                sum(HourEvents)                                 AS WindowEvents,
                uniqMerge(HourUsers)                            AS WindowUsers,
                groupArray((toUnixTimestamp(Hour), HourEvents)) AS WindowSeries
            FROM
            (
                SELECT ProjectId, IssueFingerprint, Hour, sum(Events) AS HourEvents, uniqMergeState(Users) AS HourUsers
                FROM issue_hourly
                WHERE ProjectId IN {projectIds:Array(String)}
                  AND Hour >= {from:DateTime('UTC')}
                  AND Hour <= {to:DateTime('UTC')}
                GROUP BY ProjectId, IssueFingerprint, Hour
            )
            GROUP BY ProjectId, IssueFingerprint
            ORDER BY WindowEvents DESC, IssueFingerprint
            LIMIT {candidateLimit:UInt32}
            SQL,
            [
                'projectIds' => ClickHouseParameters::stringArray($projectIds),
                'from' => $from->clone()->utc()->format('Y-m-d H:i:s'),
                'to' => $to->clone()->utc()->format('Y-m-d H:i:s'),
                'candidateLimit' => self::CANDIDATE_LIMIT,
            ],
        );

        if ($rows === null) {
            return null;
        }

        return array_map(function (array $row): array {
            $series = [];

            foreach (is_array($row['WindowSeries'] ?? null) ? $row['WindowSeries'] : [] as $point) {
                if (is_array($point) && count($point) === 2) {
                    $series[(int) array_values($point)[0]] = (int) array_values($point)[1];
                }
            }

            ksort($series);

            return [
                'projectId' => (string) ($row['ProjectId'] ?? ''),
                'fingerprint' => (string) ($row['IssueFingerprint'] ?? ''),
                'windowEvents' => (int) ($row['WindowEvents'] ?? 0),
                'windowUsers' => (int) ($row['WindowUsers'] ?? 0),
                'series' => $series,
            ];
        }, $rows);
    }

    /**
     * The all-time headline of each named issue.
     *
     * Keyed by `projectId:fingerprint`. A fingerprint is a hash of the
     * service and the stack, so the same one can exist in two projects; the
     * pair is the identity.
     *
     * @param  list<string>  $projectIds
     * @param  list<string>  $fingerprints
     * @return array<string, IssueSummary>|null
     */
    public function summaries(array $projectIds, array $fingerprints): ?array
    {
        if ($projectIds === [] || $fingerprints === []) {
            return [];
        }

        $summaries = [];

        foreach (array_chunk(array_values(array_unique($fingerprints)), self::SUMMARY_CHUNK) as $chunk) {
            $rows = $this->select(
                <<<'SQL'
                SELECT
                    ProjectId,
                    IssueFingerprint,
                    toUnixTimestamp64Milli(min(FirstSeen)) AS IssueFirstSeen,
                    toUnixTimestamp64Milli(max(LastSeen))  AS IssueLastSeen,
                    toUnixTimestamp(max(LastIngested))     AS IssueLastIngested,
                    sum(Events)                            AS IssueEvents,
                    uniqMerge(Users)                       AS IssueUsers,
                    groupUniqArrayArray(Services)          AS IssueServices,
                    groupUniqArrayArray(Environments)      AS IssueEnvironments,
                    argMaxMerge(ExceptionType)             AS IssueType,
                    argMaxMerge(Message)                   AS IssueMessage,
                    argMaxMerge(Origin)                    AS IssueOrigin,
                    argMaxMerge(LastService)               AS IssueService,
                    argMaxMerge(LastRelease)               AS IssueLastRelease,
                    argMinMerge(FirstRelease)              AS IssueFirstRelease
                FROM issue_summary
                WHERE ProjectId IN {projectIds:Array(String)}
                  AND IssueFingerprint IN {fingerprints:Array(String)}
                GROUP BY ProjectId, IssueFingerprint
                SQL,
                [
                    'projectIds' => ClickHouseParameters::stringArray($projectIds),
                    'fingerprints' => ClickHouseParameters::stringArray($chunk),
                ],
            );

            if ($rows === null) {
                return null;
            }

            foreach ($rows as $row) {
                $summary = $this->mapSummary($row);
                $summaries[$summary['projectId'].':'.$summary['fingerprint']] = $summary;
            }
        }

        return $summaries;
    }

    /**
     * One issue's headline, or null when the issue is unknown or has aged out.
     *
     * @return IssueSummary|null
     */
    public function summary(string $projectId, string $fingerprint): ?array
    {
        $summaries = $this->summaries([$projectId], [$fingerprint]);

        if ($summaries === null) {
            throw new IssueStorageUnavailable;
        }

        return $summaries[$projectId.':'.$fingerprint] ?? null;
    }

    /**
     * Issues first seen since `$firstSeenSince` — candidates for `issue_new`.
     *
     * R18 then R17: fingerprints are nominated from `issue_hourly` from
     * `$hourFrom` on (no upper bound, so a client with a fast clock still
     * lands in the set), and their headlines re-aggregated by key from
     * `issue_summary`. "New" is judged on `FirstSeen`, the client's clock, so
     * the hour nomination is exact for it. Oldest first, so a cap drops the
     * newest. Regressions are a separate read ({@see regressionCandidates()}),
     * so they can never crowd new issues out of this cap.
     *
     * @param  list<string>  $projectIds
     * @return list<AlertCandidate>|null
     */
    public function newIssueCandidates(array $projectIds, Carbon $hourFrom, Carbon $firstSeenSince, int $limit = self::CANDIDATE_LIMIT): ?array
    {
        if ($projectIds === []) {
            return [];
        }

        $rows = $this->select(
            <<<'SQL'
            SELECT
                ProjectId,
                IssueFingerprint,
                toUnixTimestamp64Milli(min(FirstSeen)) AS IssueFirstSeen,
                toUnixTimestamp(max(LastIngested))     AS IssueLastIngested,
                sum(Events)                            AS IssueEvents,
                argMaxMerge(ExceptionType)             AS IssueType,
                argMaxMerge(Message)                   AS IssueMessage,
                argMaxMerge(LastService)               AS IssueService
            FROM issue_summary
            WHERE ProjectId IN {projectIds:Array(String)}
              AND (ProjectId, IssueFingerprint) IN
              (
                  SELECT DISTINCT ProjectId, IssueFingerprint
                  FROM issue_hourly
                  WHERE ProjectId IN {projectIds:Array(String)}
                    AND Hour >= {hourFrom:DateTime('UTC')}
              )
            GROUP BY ProjectId, IssueFingerprint
            HAVING IssueFirstSeen >= {firstSeenSince:Int64}
            ORDER BY IssueFirstSeen, ProjectId, IssueFingerprint
            LIMIT {candidateLimit:UInt32}
            SQL,
            [
                'projectIds' => ClickHouseParameters::stringArray($projectIds),
                'hourFrom' => $hourFrom->clone()->utc()->startOfHour()->format('Y-m-d H:i:s'),
                'firstSeenSince' => $firstSeenSince->getTimestampMs(),
                'candidateLimit' => max(1, $limit),
            ],
        );

        return $rows === null ? null : array_map($this->mapAlertCandidate(...), $rows);
    }

    /**
     * Of these (resolved) issues, those whose last event *arrived* since
     * `$ingestedSince` — candidates for `issue_regressed`.
     *
     * Not nominated from `issue_hourly`: that table is keyed by the event's
     * client timestamp, so a buffered SDK flushing an hours-old event after
     * the resolution would land in an old hour and never be nominated, though
     * {@see IssueState} calls the issue regressed (it reads `LastIngested`,
     * the server's arrival clock). The app database already knows exactly
     * which issues are resolved, so this is a key lookup on `issue_summary`
     * (R17 re-aggregation), in chunks so the bound list stays a sane URL.
     *
     * @param  list<string>  $projectIds
     * @param  list<string>  $fingerprints
     * @return list<AlertCandidate>|null
     */
    public function regressionCandidates(array $projectIds, array $fingerprints, Carbon $ingestedSince): ?array
    {
        if ($projectIds === [] || $fingerprints === []) {
            return [];
        }

        $candidates = [];

        foreach (array_chunk(array_values(array_unique($fingerprints)), self::SUMMARY_CHUNK) as $chunk) {
            $rows = $this->select(
                <<<'SQL'
                SELECT
                    ProjectId,
                    IssueFingerprint,
                    toUnixTimestamp64Milli(min(FirstSeen)) AS IssueFirstSeen,
                    toUnixTimestamp(max(LastIngested))     AS IssueLastIngested,
                    sum(Events)                            AS IssueEvents,
                    argMaxMerge(ExceptionType)             AS IssueType,
                    argMaxMerge(Message)                   AS IssueMessage,
                    argMaxMerge(LastService)               AS IssueService
                FROM issue_summary
                WHERE ProjectId IN {projectIds:Array(String)}
                  AND IssueFingerprint IN {fingerprints:Array(String)}
                GROUP BY ProjectId, IssueFingerprint
                HAVING IssueLastIngested >= {ingestedSince:UInt32}
                ORDER BY IssueLastIngested, ProjectId, IssueFingerprint
                SQL,
                [
                    'projectIds' => ClickHouseParameters::stringArray($projectIds),
                    'fingerprints' => ClickHouseParameters::stringArray($chunk),
                    'ingestedSince' => $ingestedSince->getTimestamp(),
                ],
            );

            if ($rows === null) {
                return null;
            }

            array_push($candidates, ...array_map($this->mapAlertCandidate(...), $rows));
        }

        return $candidates;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return AlertCandidate
     */
    private function mapAlertCandidate(array $row): array
    {
        return [
            'projectId' => (string) ($row['ProjectId'] ?? ''),
            'fingerprint' => (string) ($row['IssueFingerprint'] ?? ''),
            'firstSeen' => (int) ($row['IssueFirstSeen'] ?? 0),
            'lastIngested' => (int) ($row['IssueLastIngested'] ?? 0) * 1000,
            'events' => (int) ($row['IssueEvents'] ?? 0),
            'type' => (string) ($row['IssueType'] ?? ''),
            'message' => (string) ($row['IssueMessage'] ?? ''),
            'service' => (string) ($row['IssueService'] ?? ''),
        ];
    }

    /**
     * Events and users per bucket for one issue across the window.
     *
     * Hourly rows are the floor, so a bucket is never narrower than an hour.
     * Summing partial rows straight is correct here — a sum and a uniq merge
     * are indifferent to how the rows were split — so this does not need the
     * key re-aggregation a headline read does. Empty buckets are filled in so
     * a gap on the axis means "did not happen".
     *
     * @return IssueHistogram
     */
    public function histogram(string $projectId, string $fingerprint, Carbon $from, Carbon $to): array
    {
        $interval = max(3600, TimeBuckets::interval($from, $to, self::TARGET_BUCKETS));

        $rows = $this->select(
            <<<'SQL'
            SELECT
                toUnixTimestamp(toStartOfInterval(Hour, toIntervalSecond({interval:UInt32}))) AS Bucket,
                sum(Events)      AS BucketEvents,
                uniqMerge(Users) AS BucketUsers
            FROM issue_hourly
            WHERE ProjectId = {projectId:String}
              AND Hour >= {from:DateTime('UTC')}
              AND Hour <= {to:DateTime('UTC')}
              AND IssueFingerprint = {fingerprint:String}
            GROUP BY Bucket
            ORDER BY Bucket
            SQL,
            [
                'projectId' => $projectId,
                'fingerprint' => $fingerprint,
                'interval' => $interval,
                'from' => $from->clone()->utc()->startOfHour()->format('Y-m-d H:i:s'),
                'to' => $to->clone()->utc()->format('Y-m-d H:i:s'),
            ],
        );

        $counts = [];

        foreach ($rows ?? [] as $row) {
            $counts[(int) ($row['Bucket'] ?? 0)] = [
                'events' => (int) ($row['BucketEvents'] ?? 0),
                'users' => (int) ($row['BucketUsers'] ?? 0),
            ];
        }

        $buckets = [];

        foreach (TimeBuckets::starts($from, $to, $interval) as $start) {
            $buckets[] = [
                'bucket' => $start,
                'events' => $counts[$start]['events'] ?? 0,
                'users' => $counts[$start]['users'] ?? 0,
            ];
        }

        return ['buckets' => $buckets, 'intervalSeconds' => $interval, 'unavailable' => $rows === null];
    }

    /**
     * Whether any of these projects has ever had an issue.
     *
     * Decides between "nothing has ever crashed here — set up error
     * reporting" and "nothing crashed in this window". Overload answers true,
     * so a hiccup never shows an established team the setup guide.
     *
     * @param  list<string>  $projectIds
     */
    public function hasAnyIssues(array $projectIds): bool
    {
        if ($projectIds === []) {
            return false;
        }

        $rows = $this->select(
            'SELECT 1 AS Found FROM issue_summary WHERE ProjectId IN {projectIds:Array(String)} LIMIT 1',
            ['projectIds' => ClickHouseParameters::stringArray($projectIds)],
        );

        return $rows === null || $rows !== [];
    }

    /**
     * Run a select, answering null when ClickHouse is overloaded.
     *
     * @param  array<string, scalar|null>  $params
     * @return list<array<string, mixed>>|null
     */
    private function select(string $sql, array $params): ?array
    {
        try {
            return array_values($this->client->select($sql, $params));
        } catch (ClickHouseException $exception) {
            if (! $exception->isOverload()) {
                throw $exception;
            }

            report($exception);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return IssueSummary
     */
    private function mapSummary(array $row): array
    {
        return [
            'projectId' => (string) ($row['ProjectId'] ?? ''),
            'fingerprint' => (string) ($row['IssueFingerprint'] ?? ''),
            'type' => (string) ($row['IssueType'] ?? ''),
            'message' => (string) ($row['IssueMessage'] ?? ''),
            'origin' => (string) ($row['IssueOrigin'] ?? ''),
            'service' => (string) ($row['IssueService'] ?? ''),
            'services' => $this->strings($row['IssueServices'] ?? []),
            'environments' => $this->strings($row['IssueEnvironments'] ?? []),
            'firstSeen' => (int) ($row['IssueFirstSeen'] ?? 0),
            'lastSeen' => (int) ($row['IssueLastSeen'] ?? 0),
            'lastIngested' => (int) ($row['IssueLastIngested'] ?? 0) * 1000,
            'events' => (int) ($row['IssueEvents'] ?? 0),
            'users' => (int) ($row['IssueUsers'] ?? 0),
            'firstRelease' => (string) ($row['IssueFirstRelease'] ?? ''),
            'lastRelease' => (string) ($row['IssueLastRelease'] ?? ''),
        ];
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $strings = array_values(array_filter(array_map('strval', $values), fn (string $value): bool => $value !== ''));
        sort($strings);

        return $strings;
    }
}
