<?php

namespace App\Http\Controllers\Autofix;

use App\Http\Controllers\Controller;
use App\Models\FixJob;
use App\Services\Autofix\StreamTokenIssuer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams one job's events to the browser.
 *
 * This endpoint used to live in Ayos, and the browser connected to it across an
 * origin with a signed token because that was the only way to reach a service
 * holding the events in memory. A container run holds nothing and listens for
 * nothing: it POSTs its events here, Bilis persists them, and the live view is
 * a read of a row we already own.
 *
 * Which makes this same-origin and session-authenticated, and deletes an
 * unusual amount of machinery along the way — the cross-origin CORS policy, the
 * `exp`-at-connect-time subtlety, and Ayos's replay ring buffer with it. The
 * transcript is in the database, so a reconnect is simply a query with a higher
 * `after`.
 *
 * The reads are a poll rather than a broadcast. Events arrive in ~1s batches
 * from the runner, so anything faster than this loop would be measuring its own
 * latency; a websocket here would buy nothing but a broadcasting stack.
 */
class FixJobStreamController extends Controller
{
    /**
     * How long a single connection is held open before the client reconnects.
     *
     * Bounded on purpose: a php-fpm worker is a scarce, blocking resource, and
     * an unbounded stream is a slow way to run out of them. `EventSource`
     * reconnects on its own and resumes from `Last-Event-ID`, so the seam is
     * invisible.
     */
    public const MAX_SECONDS = 300;

    /**
     * How often the transcript is re-read.
     */
    public const POLL_MS = 700;

    /**
     * Open the stream.
     */
    public function __invoke(
        Request $request,
        StreamTokenIssuer $streamTokens,
        string $current_team,
        FixJob $fixJob,
    ): StreamedResponse {
        /*
         * The session is the authority here — same origin, real user, real
         * policy. The token is checked as well, and only for what a session
         * cannot say: that the viewer asked for THIS job. A mis-wired client
         * holding a valid token for another job fails here rather than being
         * handed a transcript it is entitled to but did not ask for.
         */
        Gate::authorize('stream', $fixJob);

        abort_unless(
            $streamTokens->accepts((string) $request->query('token', ''), $fixJob),
            403,
            'The stream token is not valid for this job.',
        );

        $after = $this->resumeFrom($request);

        return response()->stream(function () use ($fixJob, $after): void {
            $this->liftExecutionLimit();

            $this->pump($fixJob, $after);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            // nginx and friends buffer a streamed response by default, which
            // for a fifteen minute job means the viewer sees nothing at all and
            // then everything at once.
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Give this request the whole stream budget.
     *
     * `php.ini-production` caps a request at 30 seconds, which is right for
     * every other route and fatal for this one: PHP kills the handler mid
     * frame, and the error handler then tries to render a response whose body
     * is already on the wire ("headers already sent"). The loop's own deadline
     * is what bounds the connection; this only stops PHP from ending it first.
     * The grace covers the last poll interval and the shutdown after it.
     */
    protected function liftExecutionLimit(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::MAX_SECONDS + 30);
        }
    }

    /**
     * Emit events until the job ends, the client leaves, or the clock runs out.
     */
    protected function pump(FixJob $fixJob, int $after): void
    {
        $deadline = microtime(true) + self::MAX_SECONDS;

        while (microtime(true) < $deadline) {
            if (connection_aborted()) {
                return;
            }

            $fixJob->refresh();

            foreach ($this->eventsAfter($fixJob, $after) as $event) {
                $seq = is_int($event['seq'] ?? null) ? $event['seq'] : null;

                if ($seq !== null) {
                    $after = max($after, $seq);
                }

                $this->send((string) $event['type'], $event, $seq);

                // `done` is the transcript's last word. Holding the connection
                // open past it would keep a worker busy for nothing.
                if ($event['type'] === 'done') {
                    return;
                }
            }

            if ($fixJob->status->isTerminal() && $this->eventsAfter($fixJob, $after) === []) {
                /*
                 * A job that came to rest without a `done` event — reaped,
                 * cancelled locally, or failed before the run ever spoke. The
                 * viewer needs the stream to end rather than hang, and the page
                 * already shows the terminal status from the row itself.
                 */
                $this->send('done', ['type' => 'done', 'data' => ['status' => $fixJob->status->value]], null);

                return;
            }

            // A comment frame. It keeps proxies and browsers from calling an
            // idle connection dead, and costs one line per second.
            echo ": ping\n\n";
            $this->flush();

            usleep(self::POLL_MS * 1000);
        }
    }

    /**
     * The persisted events after `$after`, in order.
     *
     * @return list<array<string, mixed>>
     */
    protected function eventsAfter(FixJob $fixJob, int $after): array
    {
        $events = is_array($fixJob->events) ? $fixJob->events : [];

        $fresh = [];

        foreach ($events as $event) {
            if (! is_array($event) || ! is_string($event['type'] ?? null)) {
                continue;
            }

            $seq = $event['seq'] ?? null;

            if (is_int($seq) && $seq <= $after) {
                continue;
            }

            $fresh[] = $event;
        }

        return $fresh;
    }

    /**
     * Write one SSE frame.
     *
     * The event is sent under its own `type` as the SSE event name AND as the
     * whole JSON payload, which is what the browser composable already expects
     * from the endpoint this replaced.
     *
     * @param  array<string, mixed>  $event
     */
    protected function send(string $type, array $event, ?int $seq): void
    {
        if ($seq !== null) {
            echo 'id: '.$seq."\n";
        }

        echo 'event: '.$type."\n";
        echo 'data: '.json_encode($event, JSON_UNESCAPED_SLASHES)."\n\n";

        $this->flush();
    }

    /**
     * Where a reconnecting client left off.
     *
     * `EventSource` sends `Last-Event-ID` by itself; the query parameter is for
     * everything else.
     */
    protected function resumeFrom(Request $request): int
    {
        $header = $request->header('Last-Event-ID');

        if (is_string($header) && ctype_digit($header)) {
            return (int) $header;
        }

        $after = $request->query('after');

        return is_string($after) && ctype_digit($after) ? (int) $after : 0;
    }

    /**
     * Push whatever is buffered out to the client.
     */
    protected function flush(): void
    {
        // php-fpm holds output until the handler returns unless it is told
        // otherwise, and an SSE frame nobody sees is not an SSE frame.
        if (ob_get_level() > 0) {
            @ob_flush();
        }

        flush();
    }
}
