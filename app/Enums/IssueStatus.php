<?php

namespace App\Enums;

use App\Services\Issues\IssueState;

/**
 * Where a person has put an issue.
 *
 * Only the person's decision is stored. "Regressed" is deliberately not a
 * case: it is a resolved issue whose last event is newer than the resolution,
 * which is a fact about ClickHouse at read time, not something any request
 * could write. See {@see IssueState}.
 */
enum IssueStatus: string
{
    case Unresolved = 'unresolved';
    case Resolved = 'resolved';
    case Ignored = 'ignored';

    /**
     * All enum values.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }
}
