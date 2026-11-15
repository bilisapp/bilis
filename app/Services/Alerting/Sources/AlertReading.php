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
     * `events` is how many rows (log lines, spans) the reading counted, and
     * `baseline` what the same reading was one compare offset earlier — the
     * baseline's event count for a compared `count`/`rate`, its value for a
     * compared ratio or percentile. Both are null where they do not apply
     * (every metric, an uncompared rule); a notification says them in words.
     *
     * `quiet` are the series that had data but fewer events than the rule's
     * `min_events`: they are absent from `series` (no data, by the contract),
     * and listed here only so a resolve can say "fewer than the minimum"
     * rather than "no data arrived".
     *
     * @param  list<array{labels: array<string, string>, value: float, events?: int|null, baseline?: float|null}>  $series
     * @param  list<array{labels: array<string, string>, events: int}>  $quiet
     */
    public function __construct(
        public array $series,
        public bool $truncated = false,
        public array $quiet = [],
    ) {}
}
