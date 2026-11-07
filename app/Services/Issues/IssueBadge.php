<?php

namespace App\Services\Issues;

use App\Models\Team;
use Illuminate\Support\Facades\Cache;

/**
 * The count the sidebar puts beside "Issues": what needs attention right now.
 *
 * It is the issue list's own default view — unresolved, last seven days, every
 * project — asked through {@see IssueList} so the number on the rail and the
 * number at the top of the list can never disagree about what "unresolved"
 * means (regressed issues included, {@see IssueState::matches()}).
 *
 * It is shared with every page, so it is cached per team for a minute and
 * stored as a plain array (the database cache store refuses objects). A
 * ClickHouse that cannot answer yields null, and the rail shows no count
 * rather than a zero that would read as "all clear".
 *
 * @phpstan-type IssueBadgeCounts array{unresolved: int, regressed: int}
 */
class IssueBadge
{
    public const CACHE_SECONDS = 60;

    public function __construct(private readonly IssueList $list) {}

    /**
     * @return IssueBadgeCounts|null
     */
    public function forTeam(Team $team): ?array
    {
        /** @var IssueBadgeCounts|null */
        return Cache::remember(
            "issues:badge:{$team->getKey()}",
            self::CACHE_SECONDS,
            function () use ($team): ?array {
                $result = $this->list->build($team->projects()->get(), new IssueFilters);

                if ($result['unavailable']) {
                    return null;
                }

                return [
                    'unresolved' => $result['total'],
                    'regressed' => $result['regressed'],
                ];
            },
        );
    }
}
