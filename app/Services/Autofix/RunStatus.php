<?php

namespace App\Services\Autofix;

/**
 * What a run driver can say about a run.
 *
 * Deliberately coarse. Bilis only ever needs to answer one question — "is this
 * run still capable of sending me an artifact?" — and every platform spells its
 * own state machine differently.
 */
enum RunStatus: string
{
    /** Accepted, not started yet. */
    case Queued = 'queued';

    /** Alive. An artifact may still arrive. */
    case Running = 'running';

    /** Over, whichever way. No artifact is coming that has not already come. */
    case Finished = 'finished';

    /**
     * Determine whether the run could still report.
     */
    public function isAlive(): bool
    {
        return $this !== self::Finished;
    }
}
