<?php

namespace App\Services\Plans;

use App\Models\Team;
use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;

/**
 * Where a team stands against the hosted Free plan.
 *
 * Nothing here gates anything. The numbers are shown, and when one is over
 * the app says so and points at a conversation — ingest is untouched, no
 * button is disabled, and no row is dropped. A limit that quietly deletes
 * telemetry is worse than no limit at all.
 *
 * Projects and members are counted live from SQLite: they are two cheap
 * queries and a stale count on the page a person just created a project from
 * reads as a bug. The event count is a pair of ClickHouse aggregates and is
 * cached for five minutes like `LogStorage`, with today's UTC date in the key
 * so yesterday's total can never survive midnight.
 *
 * @phpstan-type PlanEvents array{used: int, limit: int, logs: int, spans: int, since: string, unavailable: bool}
 * @phpstan-type PlanAllowance array{used: int, limit: int}
 * @phpstan-type PlanUsageResult array{plan: string, projects: PlanAllowance, members: PlanAllowance, events: PlanEvents, retentionDays: int, requestsPerMinute: int, warnAtPercent: int}
 */
class PlanUsage
{
    /**
     * How long a measured event count is reused.
     */
    private const CACHE_SECONDS = 300;

    public function __construct(
        private readonly ClickHouseClient $client,
        private readonly CacheRepository $cache,
        private readonly PlanLimits $limits,
    ) {}

    /**
     * Measure one team against the Free plan.
     *
     * @param  list<string>  $projectIds
     * @return PlanUsageResult
     */
    public function forTeam(Team $team, array $projectIds): array
    {
        $since = Carbon::now('UTC')->startOfDay();

        return [
            'plan' => 'free',
            'projects' => [
                'used' => $team->projects()->count(),
                'limit' => $this->limits->projectsPerTeam(),
            ],
            'members' => [
                'used' => $team->members()->count(),
                'limit' => $this->limits->membersPerTeam(),
            ],
            'events' => $this->events($projectIds, $since),
            'retentionDays' => $this->limits->retentionDays(),
            'requestsPerMinute' => $this->limits->requestsPerMinute(),
            'warnAtPercent' => $this->limits->warnAtPercent(),
        ];
    }

    /**
     * Log records plus spans accepted since midnight UTC.
     *
     * A team with no projects short-circuits to zeroes without touching
     * ClickHouse at all — there is nothing to count and the dashboard of a
     * brand new team must not wait on an HTTP round trip to say so.
     *
     * @param  list<string>  $projectIds
     * @return PlanEvents
     */
    private function events(array $projectIds, Carbon $since): array
    {
        $limit = $this->limits->eventsPerDay();
        $sinceLabel = $since->format('Y-m-d H:i:s.u');

        if ($projectIds === []) {
            return [
                'used' => 0,
                'limit' => $limit,
                'logs' => 0,
                'spans' => 0,
                'since' => $sinceLabel,
                'unavailable' => false,
            ];
        }

        $key = 'plans.events.'.$since->format('Y-m-d').'.'.sha1(implode(',', $projectIds));

        /** @var array{logs: int, spans: int}|null $cached */
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            return [
                'used' => $cached['logs'] + $cached['spans'],
                'limit' => $limit,
                'logs' => $cached['logs'],
                'spans' => $cached['spans'],
                'since' => $sinceLabel,
                'unavailable' => false,
            ];
        }

        try {
            $counts = $this->count($projectIds, $since);
        } catch (ClickHouseException $exception) {
            if (! $exception->isOverload()) {
                throw $exception;
            }

            /*
             * Never cached: an outage must not freeze the card into "we do not
             * know" for the whole cache window.
             */
            report($exception);

            return [
                'used' => 0,
                'limit' => $limit,
                'logs' => 0,
                'spans' => 0,
                'since' => $sinceLabel,
                'unavailable' => true,
            ];
        }

        $this->cache->put($key, $counts, self::CACHE_SECONDS);

        return [
            'used' => $counts['logs'] + $counts['spans'],
            'limit' => $limit,
            'logs' => $counts['logs'],
            'spans' => $counts['spans'],
            'since' => $sinceLabel,
            'unavailable' => false,
        ];
    }

    /**
     * The two counts, each a plain SCHEMA.md R4 range read.
     *
     * Sort key `(ProjectId, Timestamp, ...)`, so this is `ProjectId IN` and a
     * closed `Timestamp` window and nothing else — no bucket expression, and
     * every value bound as a server-side parameter.
     *
     * @param  list<string>  $projectIds
     * @return array{logs: int, spans: int}
     */
    private function count(array $projectIds, Carbon $since): array
    {
        $params = [
            'projectIds' => $this->projectIdsParameter($projectIds),
            'from' => $since->format('Y-m-d H:i:s.u'),
            'to' => Carbon::now('UTC')->format('Y-m-d H:i:s.u'),
        ];

        $logs = $this->client->select(
            'SELECT count() AS LogsToday FROM otel_logs '
            .'WHERE ProjectId IN {projectIds:Array(String)} '
            .'AND Timestamp >= {from:DateTime64(9)} AND Timestamp <= {to:DateTime64(9)}',
            $params,
        );

        $spans = $this->client->select(
            'SELECT count() AS SpansToday FROM otel_traces '
            .'WHERE ProjectId IN {projectIds:Array(String)} '
            .'AND Timestamp >= {from:DateTime64(9)} AND Timestamp <= {to:DateTime64(9)}',
            $params,
        );

        return [
            'logs' => (int) ($logs[0]['LogsToday'] ?? 0),
            'spans' => (int) ($spans[0]['SpansToday'] ?? 0),
        ];
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
}
