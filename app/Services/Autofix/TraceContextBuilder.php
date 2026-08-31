<?php

namespace App\Services\Autofix;

use App\Services\ClickHouse\ClickHouseException;
use App\Services\Traces\TraceQuery;
use Illuminate\Support\Carbon;

/**
 * Fetches the trace behind a triggering log line and freezes it onto the job.
 *
 * A log row carries a `TraceId` and a `SpanId` whenever the emitting process
 * was traced, and the trace is often the better half of the evidence: the
 * request that led to the error, the query that timed out two spans up, the
 * downstream call that failed first. This class turns that reference into a
 * compact text waterfall the agent can read and the job page can show.
 *
 * It runs when the job is raised, not when it is dispatched, for the same
 * reason the log row is frozen into `error_context`: spans age out after
 * thirty days and a pull request can be reviewed later than that. What is
 * stored is what the agent was actually handed.
 *
 * It is an enrichment and never a reason to refuse a job. Every way the
 * lookup can come up empty is recorded as a state rather than thrown — no
 * summary, spans expired, storage busy — so `TaskRenderer` can say in one
 * line why the waterfall is absent instead of leaving the agent to wonder
 * whether one was ever there. A storage exception is reported and treated as
 * `unavailable`; a fix job must not fail to be raised because a side query
 * against ClickHouse did.
 *
 * Project ids come from the repository the job is raised on — the same ids
 * the rest of the scan reads logs with — never from the row.
 *
 * @phpstan-type TraceContext array{trace_id: string, span_id: string, state: 'rendered'|'expired'|'missing'|'unavailable', root_name: string, root_service: string, started_at: string, duration_ms: float, span_count: int, error_count: int, rendered_spans: int, omitted_spans: int, truncated: bool, waterfall: string|null}
 */
class TraceContextBuilder
{
    public const STATE_RENDERED = 'rendered';

    public const STATE_EXPIRED = 'expired';

    public const STATE_MISSING = 'missing';

    public const STATE_UNAVAILABLE = 'unavailable';

    public function __construct(
        private readonly TraceQuery             $traces,
        private readonly TraceWaterfallRenderer $renderer,
    ) {
    }

    /**
     * Resolve one trace reference into the context stored on the job.
     *
     * @param list<string> $projectIds
     * @return TraceContext
     */
    public function build(array $projectIds, string $traceId, string $spanId = ''): array
    {
        $traceId = strtolower(trim($traceId));
        $spanId = strtolower(trim($spanId));

        try {
            return $this->resolve($projectIds, $traceId, $spanId);
        } catch (ClickHouseException $exception) {
            report($exception);

            return $this->context($traceId, $spanId, self::STATE_UNAVAILABLE);
        }
    }

    /**
     * @param list<string> $projectIds
     * @return TraceContext
     */
    private function resolve(array $projectIds, string $traceId, string $spanId): array
    {
        $summary = $this->traces->summary($projectIds, $traceId);

        if ($summary['unavailable']) {
            return $this->context($traceId, $spanId, self::STATE_UNAVAILABLE);
        }

        $trace = $summary['trace'];

        if ($trace === null) {
            return $this->context($traceId, $spanId, self::STATE_MISSING);
        }

        $startedAt = (string)($trace['startedAt'] ?? '');
        $endedAt = (string)($trace['endedAt'] ?? '');

        /*
         * The summary already knows when the trace started and ended, so the
         * span read is bounded by exactly that (plus a second of slack), not by
         * a guess around the start: a queue job or an agent session longer than
         * five minutes is read whole, up to TraceQuery's window cap. Only a
         * summary with no usable times falls back to the bracket around `now`.
         */
        if ($startedAt !== '' && $endedAt !== '') {
            $result = $this->traces->spansBetween(
                $projectIds,
                $traceId,
                Carbon::parse($startedAt, 'UTC')->subSecond(),
                Carbon::parse($endedAt, 'UTC')->addSecond(),
            );
        } else {
            $result = $this->traces->spans($projectIds, $traceId, Carbon::now('UTC'));
        }

        if ($result['unavailable']) {
            return $this->context($traceId, $spanId, self::STATE_UNAVAILABLE, $trace);
        }

        if ($result['spans'] === []) {
            return $this->context($traceId, $spanId, self::STATE_EXPIRED, $trace);
        }

        $rendered = $this->renderer->render($result['spans'], $spanId);

        return $this->context($traceId, $spanId, self::STATE_RENDERED, $trace, $rendered, $result['truncated']);
    }

    /**
     * Assemble the stored shape, whatever was or was not found.
     *
     * @param 'rendered'|'expired'|'missing'|'unavailable' $state
     * @param array<string, mixed>|null $trace
     * @param array{text: string, rendered: int, omitted: int}|null $rendered
     * @return TraceContext
     */
    private function context(string $traceId, string $spanId, string $state, ?array $trace = null, ?array $rendered = null, bool $truncated = false): array
    {
        return [
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'state' => $state,
            'root_name' => (string)($trace['rootName'] ?? ''),
            'root_service' => (string)($trace['rootService'] ?? ''),
            'started_at' => (string)($trace['startedAt'] ?? ''),
            'duration_ms' => (float)($trace['durationMs'] ?? 0),
            'span_count' => (int)($trace['spanCount'] ?? 0),
            'error_count' => (int)($trace['errorCount'] ?? 0),
            'rendered_spans' => $rendered['rendered'] ?? 0,
            'omitted_spans' => $rendered['omitted'] ?? 0,
            'truncated' => $truncated,
            'waterfall' => $rendered['text'] ?? null,
        ];
    }
}
