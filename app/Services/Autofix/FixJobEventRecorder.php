<?php

namespace App\Services\Autofix;

use App\Models\FixJob;
use Illuminate\Support\Facades\DB;

/**
 * Keeps one job's transcript.
 *
 * Ayos has no inbound HTTP, so it cannot be asked for its events — it pushes
 * them. Batches arrive while the run works, and the artifact carries the whole
 * transcript again at the end. Both go through here, and the merge is by `seq`
 * because neither delivery is exactly-once:
 *
 * - a batch that failed to flush is retried, so events arrive twice;
 * - batches are POSTed independently, so they can arrive out of order;
 * - the artifact's copy is authoritative and may fill gaps the live path
 *   dropped — the sink's queue is bounded on purpose and prefers losing an
 *   event to slowing the job down.
 *
 * The row is updated inside a transaction with the read: two batches landing at
 * once would otherwise each write the transcript they read, and the second
 * would silently erase the first.
 */
class FixJobEventRecorder
{
    /**
     * How many events one job's transcript may hold.
     *
     * A runaway agent must not be able to grow a database row without bound.
     * The tail is what gets kept: the end of a transcript is where the failure
     * is, and the beginning is `cloning` every time.
     */
    public const MAX_EVENTS = 5000;

    /**
     * Merge a batch of events into a job's transcript.
     *
     * @param  array<int, mixed>  $incoming
     * @return int the number of events that were new
     */
    public function record(FixJob $job, array $incoming): int
    {
        $clean = $this->sanitize($incoming);

        if ($clean === []) {
            return 0;
        }

        return (int) DB::transaction(function () use ($job, $clean): int {
            /** @var FixJob $fresh */
            $fresh = FixJob::query()->lockForUpdate()->findOrFail($job->getKey());

            $existing = is_array($fresh->events) ? $fresh->events : [];

            $bySeq = [];
            foreach ([...$existing, ...$clean] as $event) {
                $seq = is_array($event) ? ($event['seq'] ?? null) : null;
                // An event with no usable seq still belongs in the transcript;
                // it just cannot be deduplicated, so it is keyed by position.
                $bySeq[is_int($seq) ? $seq : 'x'.count($bySeq)] = $event;
            }

            ksort($bySeq, SORT_NATURAL);
            $merged = array_values($bySeq);

            if (count($merged) > self::MAX_EVENTS) {
                $merged = array_slice($merged, -self::MAX_EVENTS);
            }

            $added = count($merged) - count($existing);

            $fresh->forceFill(['events' => $merged])->save();
            $job->setAttribute('events', $merged);

            return max(0, $added);
        });
    }

    /**
     * Keep only what looks like an event.
     *
     * The payload is signed, so this is not a trust boundary — it is a shape
     * check, so one malformed entry cannot make the transcript unrenderable.
     *
     * @param  array<int, mixed>  $incoming
     * @return list<array<string, mixed>>
     */
    protected function sanitize(array $incoming): array
    {
        $clean = [];

        foreach ($incoming as $event) {
            if (! is_array($event) || ! is_string($event['type'] ?? null)) {
                continue;
            }

            $clean[] = [
                'seq' => is_int($event['seq'] ?? null) ? $event['seq'] : null,
                'ts' => is_string($event['ts'] ?? null) ? $event['ts'] : null,
                'type' => $event['type'],
                'data' => is_array($event['data'] ?? null) ? $event['data'] : [],
            ];
        }

        return $clean;
    }
}
