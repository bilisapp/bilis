<?php

namespace App\Services\Logs;

use App\Models\Team;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Decides which onboarding step a team is standing on.
 *
 * The answer is derived from real state rather than from whatever the viewer
 * happens to be filtering on: a team with no projects is told to create one, a
 * team that has projects but has never received a line is shown how to send
 * one, and a team whose logs have started flowing is never nagged again — an
 * empty *filtered* window is an empty window, not an onboarding moment.
 *
 * @phpstan-type OnboardingState array{stage: 'no-projects'|'no-logs'|'ready'}
 */
class LogOnboarding
{
    /**
     * How long "this team has logged before" is trusted without re-asking.
     *
     * Only the positive answer is cached. A team that has never logged is
     * re-checked on every request, which is what lets the page flip over the
     * moment the first line lands.
     */
    private const REMEMBER_SECONDS = 21600;

    public function __construct(
        private readonly LogQuery $logQuery,
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Resolve the onboarding state for a team from its full project list.
     *
     * @param  list<string>  $projectIds  every project the team owns, not the filtered subset
     * @return OnboardingState
     */
    public function state(Team $team, array $projectIds): array
    {
        if ($projectIds === []) {
            return ['stage' => 'no-projects'];
        }

        return ['stage' => $this->hasEverReceivedLogs($team, $projectIds) ? 'ready' : 'no-logs'];
    }

    /**
     * Whether the team has ever received a log line, short-circuited once true.
     *
     * @param  list<string>  $projectIds
     */
    private function hasEverReceivedLogs(Team $team, array $projectIds): bool
    {
        $key = $this->cacheKey($team);

        if ($this->cache->get($key) === true) {
            return true;
        }

        if (! $this->logQuery->hasAnyLogs($projectIds)) {
            return false;
        }

        $this->cache->put($key, true, self::REMEMBER_SECONDS);

        return true;
    }

    /**
     * The per-team cache key for the "has logged before" flag.
     */
    private function cacheKey(Team $team): string
    {
        return 'logs.onboarding.received.'.$team->id;
    }
}
