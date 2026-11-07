<?php

namespace App\Services\Issues;

use App\Enums\IssueStatus;
use App\Models\Issue;

/**
 * The status an issue is shown with.
 *
 * Four answers from two facts: the person's decision (an `issues` row, or
 * none) and when the issue last occurred (ClickHouse). "Regressed" is never
 * stored — a resolved issue that has occurred since it was resolved is
 * regressed by definition, and computing it here means it cannot drift from
 * the data the way a flag written by a background job could.
 */
class IssueState
{
    public const UNRESOLVED = 'unresolved';

    public const RESOLVED = 'resolved';

    public const IGNORED = 'ignored';

    public const REGRESSED = 'regressed';

    /**
     * Derive the displayed status.
     *
     * @param  int  $lastSeenMs  the issue's last event, Unix milliseconds
     */
    public static function of(?Issue $issue, int $lastSeenMs): string
    {
        if ($issue === null) {
            return self::UNRESOLVED;
        }

        return match ($issue->status) {
            IssueStatus::Ignored => self::IGNORED,
            IssueStatus::Unresolved => self::UNRESOLVED,
            IssueStatus::Resolved => $issue->resolved_at !== null && $lastSeenMs > $issue->resolved_at->getTimestampMs()
                ? self::REGRESSED
                : self::RESOLVED,
        };
    }

    /**
     * Whether a displayed status belongs under the list's status filter.
     *
     * "Unresolved" means "needs attention", so it includes regressed issues.
     */
    public static function matches(string $state, string $filter): bool
    {
        return match ($filter) {
            'all' => true,
            self::UNRESOLVED => $state === self::UNRESOLVED || $state === self::REGRESSED,
            default => $state === $filter,
        };
    }
}
