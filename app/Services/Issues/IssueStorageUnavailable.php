<?php

namespace App\Services\Issues;

use RuntimeException;

/**
 * ClickHouse was too busy to answer a point lookup the page cannot do without.
 *
 * The list degrades to an "unavailable" state on its own; an issue page has no
 * useful half without its headline, so the lookup throws this instead and the
 * controller answers with a retryable error.
 */
class IssueStorageUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Issue storage is temporarily unavailable.');
    }
}
