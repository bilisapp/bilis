<?php

namespace App\Services\Ingest\Envelope;

use App\Services\Ingest\LogSeverity;
use App\Services\Ingest\LogTimestamp;
use App\Services\Ingest\MappedLogs;
use App\Services\Ingest\TraceIds;

/**
 * Maps the error events an error reporting client sends into `otel_logs` rows.
 *
 * Such an event is an exception (or a message) with a great deal of context
 * hanging off it. Bilis stores logs, so the event becomes one row: the thrown
 * exception is the body, and the context — frames, tags, breadcrumbs, user,
 * release — is flattened into LogAttributes.
 *
 * The attribute names are OpenTelemetry's, not the wire format's: an event
 * that arrives here is searched next to everything else in the table, so it is
 * named the way everything else in the table is named.
 */
class ErrorEventMapper
{
    /**
     * The most stack frames rendered into the readable stacktrace attribute.
     */
    private const MAX_FRAMES = 50;

    /**
     * The most breadcrumbs kept, most recent first.
     */
    private const MAX_BREADCRUMBS = 25;

    /**
     * The byte budget for the structured frames attribute.
     *
     * Source context makes frames heavy, and the attribute rides along with
     * every event of a busy issue. Past the budget the context goes first,
     * then frames from the bottom of the stack.
     */
    private const MAX_FRAMES_JSON_BYTES = 32_768;

    /**
     * The most source lines kept on either side of a frame's line.
     */
    private const MAX_CONTEXT_LINES = 5;

    /**
     * The most causes kept in the exception chain.
     */
    private const MAX_CHAIN = 5;

    /**
     * Map a batch of decoded error events for the given project.
     *
     * The project id is the one the public key authenticated to, never the one
     * in the DSN path or the payload (SCHEMA.md R2).
     *
     * @param  array<int, array<string, mixed>>  $events
     */
    public function map(array $events, string $projectId): MappedLogs
    {
        $rows = [];
        $rejected = 0;
        $observedAt = LogTimestamp::now();

        foreach ($events as $event) {
            $row = $this->row($event, $projectId, $observedAt);

            if ($row === null) {
                $rejected++;

                continue;
            }

            $rows[] = $row;
        }

        return new MappedLogs($rows, $rejected);
    }

    /**
     * Build one `otel_logs` row, or null when the event carries no message.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>|null
     */
    private function row(array $event, string $projectId, string $observedAt): ?array
    {
        $exception = $this->thrownException($event);
        $body = $this->body($event, $exception);

        if ($body === null) {
            return null;
        }

        $trace = $this->context($event, 'trace');
        $sdk = $this->object($event['sdk'] ?? null);

        /*
         * An event with an exception and no level is an error: that is what an
         * SDK's `captureException` means, whether or not it says so.
         */
        $level = $this->string($event['level'] ?? null);
        $level = $level === '' ? ($exception !== null ? 'error' : 'info') : $level;

        [$severityNumber, $severityText] = LogSeverity::resolve(null, $level);

        // The wire format's level names are its own ("warning", "fatal"); the column
        // holds OpenTelemetry's, so a recognised level is written canonically.
        $severityText = $severityNumber > 0 ? LogSeverity::textForNumber($severityNumber) : $severityText;

        return [
            'Timestamp' => LogTimestamp::parse($event['timestamp'] ?? null) ?? $observedAt,
            'TraceId' => $this->hex($trace['trace_id'] ?? null, 32),
            'SpanId' => $this->hex($trace['span_id'] ?? null, 16),
            'TraceFlags' => 0,
            'SeverityText' => $severityText,
            'SeverityNumber' => $severityNumber,
            'ServiceName' => $this->serviceName($event),
            'Body' => $body,
            'ResourceSchemaUrl' => '',
            'ResourceAttributes' => [],
            'ScopeSchemaUrl' => '',
            'ScopeName' => $this->string($sdk['name'] ?? null),
            'ScopeVersion' => $this->string($sdk['version'] ?? null),
            'ScopeAttributes' => [],
            'LogAttributes' => $this->attributes($event, $exception),
            'EventName' => $exception !== null ? 'exception' : '',
            'ProjectId' => $projectId,
        ];
    }

    /**
     * The exception the event is actually reporting.
     *
     * The chain arrives oldest cause first, so the last entry is the one that
     * was thrown.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>|null
     */
    private function thrownException(array $event): ?array
    {
        $values = $this->object($event['exception'] ?? null)['values'] ?? null;

        if (! is_array($values) || $values === []) {
            return null;
        }

        $last = end($values);

        return is_array($last) ? $last : null;
    }

    /**
     * The log body: the thrown exception, or the event's message.
     *
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>|null  $exception
     */
    private function body(array $event, ?array $exception): ?string
    {
        if ($exception !== null) {
            $type = $this->string($exception['type'] ?? null);
            $value = $this->string($exception['value'] ?? null);

            $body = trim($type.($type !== '' && $value !== '' ? ': ' : '').$value);

            if ($body !== '') {
                return $body;
            }
        }

        foreach ([$event['message'] ?? null, $event['logentry'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }

            if (is_array($candidate)) {
                $message = $this->string($candidate['formatted'] ?? $candidate['message'] ?? null);

                if ($message !== '') {
                    return $message;
                }
            }
        }

        return null;
    }

    /**
     * The service the event belongs to.
     *
     * The wire format has no notion of a service, so the tags a client is
     * usually configured with come first and the reporting host is the
     * fallback.
     *
     * @param  array<string, mixed>  $event
     */
    private function serviceName(array $event): string
    {
        $tags = $this->object($event['tags'] ?? null);

        foreach (['service.name', 'service_name', 'service'] as $tag) {
            $value = $this->string($tags[$tag] ?? null);

            if ($value !== '') {
                return $value;
            }
        }

        return $this->string($event['server_name'] ?? null);
    }

    /**
     * Flatten the event into the attribute map.
     *
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>|null  $exception
     * @return array<string, string>
     */
    private function attributes(array $event, ?array $exception): array
    {
        $attributes = [];

        /*
         * The event's own fields, renamed to the OpenTelemetry conventions
         * that already exist for them. The level is not among them: it is a
         * severity, and it is already in the severity columns.
         */
        foreach ([
            'event.id' => $event['event_id'] ?? null,
            'logger.name' => $event['logger'] ?? null,
            'transaction.name' => $event['transaction'] ?? null,
            'deployment.environment' => $event['environment'] ?? null,
            'service.version' => $event['release'] ?? null,
            'service.dist' => $event['dist'] ?? null,
            'host.name' => $event['server_name'] ?? null,
            'telemetry.sdk.language' => $event['platform'] ?? null,
        ] as $key => $value) {
            $this->put($attributes, $key, $this->string($value));
        }

        if ($exception !== null) {
            $frames = $this->frames($exception);

            $this->put($attributes, 'exception.type', $this->string($exception['type'] ?? null));
            $this->put($attributes, 'exception.message', $this->string($exception['value'] ?? null));
            $this->put($attributes, 'exception.module', $this->string($exception['module'] ?? null));
            $this->put($attributes, 'exception.stacktrace', $this->stacktrace($frames));
            $this->put($attributes, 'exception.origin', $this->origin($frames));
            $this->put($attributes, 'exception.frames', $this->framesJson($frames));
            $this->put($attributes, 'exception.chain', $this->chainJson($event));

            $mechanism = $this->object($exception['mechanism'] ?? null);
            $this->put($attributes, 'exception.mechanism', $this->string($mechanism['type'] ?? null));

            if (array_key_exists('handled', $mechanism)) {
                $this->put($attributes, 'exception.handled', $this->scalar($mechanism['handled']));
            }
        }

        foreach ($this->object($event['tags'] ?? null) as $key => $value) {
            $this->put($attributes, 'tag.'.$key, $this->scalar($value));
        }

        foreach ($this->object($event['extra'] ?? null) as $key => $value) {
            $this->put($attributes, 'extra.'.$key, $this->scalar($value));
        }

        $user = $this->object($event['user'] ?? null);

        foreach (['id' => 'user.id', 'username' => 'user.name', 'email' => 'user.email', 'ip_address' => 'user.ip'] as $field => $key) {
            $this->put($attributes, $key, $this->scalar($user[$field] ?? null));
        }

        $request = $this->object($event['request'] ?? null);
        $this->put($attributes, 'http.url', $this->string($request['url'] ?? null));
        $this->put($attributes, 'http.request.method', $this->string($request['method'] ?? null));
        $this->put($attributes, 'url.query', $this->scalar($request['query_string'] ?? null));
        $this->put($attributes, 'user_agent.original', $this->header($request, 'user-agent'));

        foreach ([
            'os' => ['name' => 'os.name', 'version' => 'os.version'],
            'browser' => ['name' => 'browser.name', 'version' => 'browser.version'],
            'device' => ['model' => 'device.model', 'family' => 'device.family'],
        ] as $context => $fields) {
            $values = $this->context($event, $context);

            foreach ($fields as $field => $key) {
                $this->put($attributes, $key, $this->scalar($values[$field] ?? null));
            }
        }

        /*
         * Which client reported this is the client's own name for itself, and
         * the one place the reporting stack is identifiable — worth keeping,
         * and it belongs under the standard telemetry.sdk.* names.
         */
        $sdk = $this->object($event['sdk'] ?? null);
        $this->put($attributes, 'telemetry.sdk.name', $this->string($sdk['name'] ?? null));
        $this->put($attributes, 'telemetry.sdk.version', $this->string($sdk['version'] ?? null));

        $runtime = $this->context($event, 'runtime');
        $this->put($attributes, 'process.runtime.name', $this->string($runtime['name'] ?? null));
        $this->put($attributes, 'process.runtime.version', $this->string($runtime['version'] ?? null));

        $this->put($attributes, 'breadcrumbs', $this->breadcrumbs($event));
        $this->put($attributes, 'breadcrumbs.json', $this->breadcrumbsJson($event));

        return $attributes;
    }

    /**
     * The stack frames of an exception, most recent call first.
     *
     * They arrive the other way round, oldest frame first.
     *
     * @param  array<string, mixed>  $exception
     * @return array<int, array<string, mixed>>
     */
    private function frames(array $exception): array
    {
        $frames = $this->object($exception['stacktrace'] ?? null)['frames'] ?? null;

        if (! is_array($frames)) {
            return [];
        }

        $frames = array_values(array_filter($frames, 'is_array'));

        return array_reverse($frames);
    }

    /**
     * Render frames the way a stack trace is normally read.
     *
     * There is no exception UI in the log viewer yet, so the trace has to be
     * legible as the text of an attribute.
     *
     * @param  array<int, array<string, mixed>>  $frames
     */
    private function stacktrace(array $frames): string
    {
        $lines = [];

        foreach (array_slice($frames, 0, self::MAX_FRAMES) as $frame) {
            $lines[] = '#'.count($lines).' '.$this->frameLocation($frame);
        }

        $dropped = count($frames) - count($lines);

        if ($dropped > 0) {
            $lines[] = "... {$dropped} more";
        }

        return implode("\n", $lines);
    }

    /**
     * Where the exception was thrown: the innermost application frame.
     *
     * @param  array<int, array<string, mixed>>  $frames
     */
    private function origin(array $frames): string
    {
        foreach ($frames as $frame) {
            if (($frame['in_app'] ?? false) === true) {
                return $this->frameLocation($frame);
            }
        }

        return $frames === [] ? '' : $this->frameLocation($frames[0]);
    }

    /**
     * One frame as `file:line function()`.
     *
     * @param  array<string, mixed>  $frame
     */
    private function frameLocation(array $frame): string
    {
        $file = $this->string($frame['filename'] ?? $frame['abs_path'] ?? null);
        $line = $frame['lineno'] ?? null;
        $function = $this->string($frame['function'] ?? null);

        $location = $file.(is_int($line) || is_string($line) ? ':'.$line : '');

        return trim($location.($function === '' ? '' : ' '.$function.'()'));
    }

    /**
     * The event's breadcrumbs as one readable block, most recent first.
     *
     * @param  array<string, mixed>  $event
     */
    private function breadcrumbs(array $event): string
    {
        $crumbs = $event['breadcrumbs'] ?? null;
        $crumbs = is_array($crumbs) ? ($crumbs['values'] ?? $crumbs) : null;

        if (! is_array($crumbs) || $crumbs === []) {
            return '';
        }

        $lines = [];

        foreach (array_slice(array_reverse(array_values($crumbs)), 0, self::MAX_BREADCRUMBS) as $crumb) {
            if (! is_array($crumb)) {
                continue;
            }

            $category = $this->string($crumb['category'] ?? $crumb['type'] ?? null);
            $message = $this->string($crumb['message'] ?? null);

            $lines[] = trim(($category === '' ? '' : "[{$category}] ").$message);
        }

        return implode("\n", array_filter($lines, fn (string $line): bool => $line !== ''));
    }

    /**
     * The frames as structured JSON, for the issue page's stack trace.
     *
     * The text attribute stays what search and the fingerprint read; this one
     * keeps what text cannot: which frames are the application's own and the
     * source lines around each call. Context is kept for every frame not
     * marked `in_app: false`, and the whole value is held under
     * {@see MAX_FRAMES_JSON_BYTES} (context goes first, then bottom frames).
     *
     * @param  array<int, array<string, mixed>>  $frames
     */
    private function framesJson(array $frames): string
    {
        if ($frames === []) {
            return '';
        }

        $structured = [];

        foreach (array_slice($frames, 0, self::MAX_FRAMES) as $frame) {
            /*
             * The SDK's verdict when it gave one; absent, the flag is left
             * out so the issue page can apply its own path heuristic, rather
             * than every frame of such an SDK being written down as library
             * code and losing its source.
             */
            $inApp = is_bool($frame['in_app'] ?? null) ? $frame['in_app'] : null;
            $line = $frame['lineno'] ?? null;
            $column = $frame['colno'] ?? null;

            $entry = array_filter([
                'filename' => $this->string($frame['filename'] ?? null),
                'abs_path' => $this->string($frame['abs_path'] ?? null),
                'function' => $this->string($frame['function'] ?? null),
                'module' => $this->string($frame['module'] ?? null),
                'lineno' => is_int($line) ? $line : null,
                'colno' => is_int($column) ? $column : null,
            ], fn (mixed $value): bool => $value !== null && $value !== '');

            if ($inApp !== null) {
                $entry['in_app'] = $inApp;
            }

            // Source is kept unless the SDK said the frame is not the application's.
            if ($inApp !== false && is_string($frame['context_line'] ?? null)) {
                $entry['pre_context'] = $this->contextLines($frame['pre_context'] ?? null, fromEnd: true);
                $entry['context_line'] = $this->line($frame['context_line']);
                $entry['post_context'] = $this->contextLines($frame['post_context'] ?? null, fromEnd: false);
            }

            $structured[] = $entry;
        }

        $json = $this->json($structured);

        if (strlen($json) > self::MAX_FRAMES_JSON_BYTES) {
            $structured = array_map(
                fn (array $entry): array => array_diff_key($entry, array_flip(['pre_context', 'context_line', 'post_context'])),
                $structured,
            );
            $json = $this->json($structured);
        }

        while (strlen($json) > self::MAX_FRAMES_JSON_BYTES && $structured !== []) {
            array_pop($structured);
            $json = $this->json($structured);
        }

        return $structured === [] ? '' : $json;
    }

    /**
     * The causes behind the thrown exception, nearest cause first, as JSON.
     *
     * The chain arrives oldest cause first with the thrown exception last; the
     * thrown one is already the body and the exception.* attributes.
     *
     * @param  array<string, mixed>  $event
     */
    private function chainJson(array $event): string
    {
        $values = $this->object($event['exception'] ?? null)['values'] ?? null;

        if (! is_array($values) || count($values) < 2) {
            return '';
        }

        $causes = [];

        foreach (array_reverse(array_slice(array_values($values), 0, -1)) as $cause) {
            if (! is_array($cause)) {
                continue;
            }

            $frames = $this->frames($cause);

            $causes[] = array_filter([
                'type' => $this->string($cause['type'] ?? null),
                'message' => $this->line($cause['value'] ?? null, 1000),
                'origin' => $this->origin($frames),
                'stacktrace' => $this->stacktrace(array_slice($frames, 0, 10)),
            ], fn (string $value): bool => $value !== '');

            if (count($causes) === self::MAX_CHAIN) {
                break;
            }
        }

        $causes = array_values(array_filter($causes));

        return $causes === [] ? '' : $this->json($causes);
    }

    /**
     * The breadcrumbs as structured JSON, most recent first.
     *
     * @param  array<string, mixed>  $event
     */
    private function breadcrumbsJson(array $event): string
    {
        $crumbs = $event['breadcrumbs'] ?? null;
        $crumbs = is_array($crumbs) ? ($crumbs['values'] ?? $crumbs) : null;

        if (! is_array($crumbs) || $crumbs === []) {
            return '';
        }

        $structured = [];

        foreach (array_slice(array_reverse(array_values($crumbs)), 0, self::MAX_BREADCRUMBS) as $crumb) {
            if (! is_array($crumb)) {
                continue;
            }

            $timestamp = LogTimestamp::parse($crumb['timestamp'] ?? null);
            $data = $this->object($crumb['data'] ?? null);

            $entry = array_filter([
                'timestamp' => $timestamp ?? '',
                'type' => $this->string($crumb['type'] ?? null),
                'category' => $this->string($crumb['category'] ?? null),
                'level' => $this->string($crumb['level'] ?? null),
                'message' => $this->line($crumb['message'] ?? null, 500),
                'data' => $data === [] ? '' : $this->line($this->json($data), 500),
            ], fn (string $value): bool => $value !== '');

            if ($entry !== []) {
                $structured[] = $entry;
            }
        }

        return $structured === [] ? '' : $this->json($structured);
    }

    /**
     * Up to {@see MAX_CONTEXT_LINES} source lines, nearest the call kept.
     *
     * @return list<string>
     */
    private function contextLines(mixed $lines, bool $fromEnd): array
    {
        if (! is_array($lines)) {
            return [];
        }

        $lines = array_values(array_map(fn (mixed $line): string => $this->line($line), $lines));

        return $fromEnd
            ? array_slice($lines, -self::MAX_CONTEXT_LINES)
            : array_slice($lines, 0, self::MAX_CONTEXT_LINES);
    }

    /**
     * One line of text, capped, with its indentation kept.
     */
    private function line(mixed $value, int $limit = 300): string
    {
        if (! is_string($value)) {
            return '';
        }

        $value = rtrim($value);

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit).'…' : $value;
    }

    /**
     * Read one request header case-insensitively; it may be a map or a list of pairs.
     *
     * @param  array<string, mixed>  $request
     */
    private function header(array $request, string $name): string
    {
        $headers = $request['headers'] ?? null;

        if (! is_array($headers)) {
            return '';
        }

        foreach ($headers as $key => $value) {
            if (is_array($value) && array_is_list($value) && count($value) === 2) {
                [$key, $value] = $value;
            }

            if (is_string($key) && strcasecmp($key, $name) === 0) {
                return $this->line($value, 500);
            }
        }

        return '';
    }

    /**
     * Encode a value the way every JSON attribute is encoded.
     */
    private function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * One context object off the event, by name.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function context(array $event, string $name): array
    {
        return $this->object($this->object($event['contexts'] ?? null)[$name] ?? null);
    }

    /**
     * Keep an attribute only when it carries something.
     *
     * @param  array<string, string>  $attributes
     */
    private function put(array &$attributes, string $key, string $value): void
    {
        if ($value !== '') {
            $attributes[$key] = $value;
        }
    }

    /**
     * Read a value as a JSON object.
     *
     * @return array<string, mixed>
     */
    private function object(mixed $value): array
    {
        return is_array($value) && ! array_is_list($value) ? $value : [];
    }

    /**
     * Read a trace or span id, keeping only a well formed one.
     *
     * They are already the hex OpenTelemetry uses, so a valid one goes into
     * the column untouched and anything else is dropped rather than stored as
     * an id no trace will ever join on.
     */
    private function hex(mixed $value, int $length): string
    {
        // The shared normaliser, so an id written here spells the same as the
        // span it points at; the envelope's ids are hex (dashed or not), and
        // one that is not resolvable is dropped rather than stored raw.
        return TraceIds::hex($value, intdiv($length, 2));
    }

    /**
     * Read a value that should be a string.
     */
    private function string(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * Coerce any value into the string ClickHouse expects.
     */
    private function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_string($value) => trim($value),
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => rtrim(rtrim(sprintf('%.10F', $value), '0'), '.'),
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }
}
