<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;
use OpenTelemetry\API\Trace\Span;

/**
 * A Monolog tap that stamps every record with the span it was written inside.
 *
 * This is the join between the two signals: a log row carrying `TraceId` and
 * `SpanId` links to its waterfall, and the span links back to the lines it
 * emitted. `BilisHandler` lifts both ids out of `extra` onto the top-level
 * fields the ingest endpoint reads, so nothing else has to know about this.
 *
 * The same class is published on the Shippers documentation page for other
 * people's applications to copy — keep the two in step.
 */
class AddTraceContext
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(function (LogRecord $record): LogRecord {
            $context = Span::getCurrent()->getContext();

            /*
             * Outside a recorded span — a console command with no
             * instrumentation, a queue worker booting, or the whole
             * application when the SDK is disabled — the current span is a
             * no-op whose ids are all zeroes. An all-zero id is not a missing
             * id: it would be stored, indexed, and joined to every other line
             * written outside a span.
             */
            if (! $context->isValid()) {
                return $record;
            }

            return $record->with(extra: [
                ...$record->extra,
                'trace_id' => $context->getTraceId(),
                'span_id' => $context->getSpanId(),
            ]);
        });
    }
}
