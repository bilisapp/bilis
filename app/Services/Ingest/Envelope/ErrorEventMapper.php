<?php

namespace App\Services\Ingest\Envelope;

use App\Services\Ingest\LogSeverity;
use App\Services\Ingest\LogTimestamp;
use App\Services\Ingest\MappedLogs;

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
        if (! is_string($value)) {
            return '';
        }

        $value = strtolower(str_replace('-', '', trim($value)));

        return preg_match('/^[0-9a-f]{'.$length.'}$/', $value) === 1 ? $value : '';
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
