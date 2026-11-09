<?php

namespace App\Services\Alerting\Sources;

/**
 * One successful evaluation read: the series that had data, and whether the
 * source left any out.
 *
 * A source keeps at most `alerting.max_instances` series — the most extreme
 * for the rule's condition — and says `truncated` when more existed (or, for
 * metrics, when the explorer's own read cap dropped series). A truncated
 * reading says nothing about the series it did not return, so the job leaves
 * every known instance missing from it exactly as it was instead of running
 * it through the no-data path.
 */
final readonly class AlertReading
{
    /**
     * @param  list<array{labels: array<string, string>, value: float}>  $series
     */
    public function __construct(
        public array $series,
        public bool $truncated = false,
    ) {}
}
