<?php

namespace App\Services\Autofix;

use App\Services\Traces\SpanTree;

/**
 * Renders a trace's spans as a compact, token-bounded text waterfall.
 *
 * This is the trace as the fixing agent sees it: one line per span, indented
 * by depth, carrying the service, the name, the kind, the duration and the
 * status, followed by the handful of attributes that actually help someone
 * locate a bug — the route, the status code, the SQL, the RPC method, the
 * exception event, the code location. Everything else a span carries (hosts,
 * SDK versions, session ids) is left out on purpose; it is what the UI shows a
 * person browsing, not what a model needs to find the line that broke.
 *
 * Two spans are marked. The span that emitted the triggering log line is what
 * the log row's `SpanId` points at, and it is where the agent should start
 * reading; any span whose status is `Error` is marked too, because a failure
 * two services upstream is often the cause the log line is only a symptom of.
 *
 * The output is bounded twice, by span count and by characters, because it is
 * appended to a prompt whose other parts are already bounded (`TaskRenderer`)
 * and a two-thousand-span trace would drown them. When the cap bites, the
 * spans kept are the ones most likely to matter: the triggering span and its
 * ancestors, then its siblings, then every failed span, then the slowest of
 * whatever is left. The rendering order is always the tree's own, so what
 * survives still reads as a waterfall rather than as a ranking.
 *
 * Pure: no clock, no storage, no config.
 *
 * @phpstan-type Span array<string, mixed>
 * @phpstan-type RenderedWaterfall array{text: string, rendered: int, omitted: int}
 */
class TraceWaterfallRenderer
{
    /**
     * How many spans travel with the job at most.
     */
    public const MAX_SPANS = 60;

    /**
     * How many characters the waterfall may occupy in the prompt.
     */
    public const MAX_CHARS = 3000;

    /**
     * How many curated attributes one span line may carry.
     */
    public const ATTRIBUTES_PER_SPAN = 6;

    /**
     * How long one attribute value may be before it is cut.
     */
    public const VALUE_LIMIT = 100;

    /**
     * How long a database statement may be — longer than other values,
     * because the table and the predicate are usually what identifies it.
     */
    public const STATEMENT_LIMIT = 160;

    /**
     * Marks the span that emitted the triggering log line.
     */
    public const TRIGGER_MARK = '>>';

    /**
     * Marks a span whose status is Error.
     */
    public const ERROR_MARK = '!!';

    /**
     * The attributes worth a debugging agent's attention, in output order.
     *
     * Both the stable and the older HTTP semantic-convention spellings are
     * listed because exporters in the wild still send either; the first one a
     * span carries wins and its sibling is dropped, so a span never says its
     * method twice.
     *
     * @var list<list<string>>
     */
    private const ATTRIBUTE_KEYS = [
        ['http.request.method', 'http.method'],
        ['http.route'],
        ['url.path', 'http.target'],
        ['http.response.status_code', 'http.status_code'],
        ['db.system'],
        ['db.query.text', 'db.statement'],
        ['rpc.system'],
        ['rpc.service'],
        ['rpc.method'],
        ['rpc.grpc.status_code'],
        ['messaging.system'],
        ['messaging.destination.name', 'messaging.destination'],
        ['messaging.operation.type', 'messaging.operation'],
        ['code.function.name', 'code.function'],
        ['code.file.path', 'code.filepath'],
        ['code.line.number', 'code.lineno'],
    ];

    /**
     * The attribute keys whose values are statements rather than words.
     *
     * @var list<string>
     */
    private const STATEMENT_KEYS = ['db.query.text', 'db.statement'];

    /**
     * Render the spans, marking the one the triggering log line came from.
     *
     * The spans are in whatever order storage returned them; the tree order
     * comes from {@see SpanTree::flatten()}, which never drops a span.
     *
     * @param list<Span> $spans
     * @return RenderedWaterfall
     */
    public function render(array $spans, string $triggerSpanId = ''): array
    {
        $ordered = SpanTree::flatten($spans);
        $total = count($ordered);

        if ($total === 0) {
            return ['text' => '', 'rendered' => 0, 'omitted' => 0];
        }

        $kept = $this->select($ordered, $triggerSpanId);

        $lines = [$this->legend($triggerSpanId !== '')];
        $length = mb_strlen($lines[0]);
        $rendered = 0;

        foreach ($ordered as $span) {
            $spanId = $this->string($span, 'spanId');

            if (!isset($kept[$spanId])) {
                continue;
            }

            $line = $this->line($span, $spanId === $triggerSpanId);
            $cost = mb_strlen($line) + 1;

            /*
             * The character cap is checked line by line so a span is either
             * whole or absent: a waterfall row cut mid-attribute reads as a
             * value that was never there.
             */
            if ($length + $cost > self::MAX_CHARS) {
                break;
            }

            $lines[] = $line;
            $length += $cost;
            $rendered++;
        }

        $omitted = $total - $rendered;

        if ($omitted > 0) {
            $lines[] = sprintf('(%d more span%s omitted)', $omitted, $omitted === 1 ? '' : 's');
        }

        return [
            'text' => implode("\n", $lines),
            'rendered' => $rendered,
            'omitted' => $omitted,
        ];
    }

    /**
     * Decide which spans survive the count cap.
     *
     * Everything survives a small trace. A large one keeps, in priority order:
     * the triggering span and its ancestors (the call path that led there), its
     * siblings (what else that parent was doing), every span that failed, and
     * finally the slowest of the rest. The result is a set keyed by span id;
     * ordering is the caller's.
     *
     * @param list<Span> $ordered
     * @return array<string, true>
     */
    private function select(array $ordered, string $triggerSpanId): array
    {
        $kept = [];

        if (count($ordered) <= self::MAX_SPANS) {
            foreach ($ordered as $span) {
                $kept[$this->string($span, 'spanId')] = true;
            }

            return $kept;
        }

        /** @var array<string, Span> $byId */
        $byId = [];

        foreach ($ordered as $span) {
            $byId[$this->string($span, 'spanId')] = $span;
        }

        $keep = function (string $spanId) use (&$kept): bool {
            if ($spanId === '' || isset($kept[$spanId]) || count($kept) >= self::MAX_SPANS) {
                return false;
            }

            $kept[$spanId] = true;

            return true;
        };

        if ($triggerSpanId !== '' && isset($byId[$triggerSpanId])) {
            /*
             * The path from the triggering span up to the root, guarded against
             * a parent cycle the same way SpanTree is.
             */
            $seen = [];
            $current = $triggerSpanId;

            while ($current !== '' && isset($byId[$current]) && !isset($seen[$current])) {
                $seen[$current] = true;
                $keep($current);
                $current = $this->string($byId[$current], 'parentSpanId');
            }

            $parentId = $this->string($byId[$triggerSpanId], 'parentSpanId');

            if ($parentId !== '') {
                foreach ($ordered as $span) {
                    if ($this->string($span, 'parentSpanId') === $parentId) {
                        $keep($this->string($span, 'spanId'));
                    }
                }
            }
        }

        foreach ($ordered as $span) {
            if ($this->isError($span)) {
                $keep($this->string($span, 'spanId'));
            }
        }

        $slowest = $ordered;

        usort($slowest, fn(array $a, array $b): int => $this->duration($b) <=> $this->duration($a));

        foreach ($slowest as $span) {
            if (count($kept) >= self::MAX_SPANS) {
                break;
            }

            $keep($this->string($span, 'spanId'));
        }

        return $kept;
    }

    /**
     * The line that explains the two marks before they are used.
     */
    private function legend(bool $hasTrigger): string
    {
        $parts = [];

        if ($hasTrigger) {
            $parts[] = self::TRIGGER_MARK . ' = the span that emitted the triggering log line';
        }

        $parts[] = self::ERROR_MARK . ' = a span whose status is Error';

        return 'Legend: ' . implode('; ', $parts);
    }

    /**
     * One span as one line: marks, indent, identity, timing, outcome, facts.
     *
     * @param Span $span
     */
    private function line(array $span, bool $isTrigger): string
    {
        $isError = $this->isError($span);
        $depth = max(0, (int)($span['depth'] ?? 0));

        $marks = ($isTrigger ? self::TRIGGER_MARK : '  ') . ($isError ? self::ERROR_MARK : '  ');

        $head = sprintf(
            '%s %s%s %s [%s] %s',
            $marks,
            str_repeat('  ', $depth),
            $this->string($span, 'serviceName') ?: '(no service)',
            $this->string($span, 'name') ?: '(unnamed)',
            $this->string($span, 'kind') ?: 'Unspecified',
            $this->formatDuration($this->duration($span)),
        );

        $status = $this->string($span, 'statusCode');

        if ($status !== '' && $status !== 'Unset') {
            $message = $this->string($span, 'statusMessage');
            $head .= ' ' . $status . ($message !== '' ? ': ' . $this->truncate($message, self::VALUE_LIMIT) : '');
        }

        $facts = $this->facts($span);

        return $facts === [] ? $head : $head . ' | ' . implode(' ', $facts);
    }

    /**
     * The curated attributes and exception events of one span.
     *
     * @param Span $span
     * @return list<string>
     */
    private function facts(array $span): array
    {
        $attributes = is_array($span['attributes'] ?? null) ? $span['attributes'] : [];
        $facts = [];

        foreach (self::ATTRIBUTE_KEYS as $aliases) {
            if (count($facts) >= self::ATTRIBUTES_PER_SPAN) {
                break;
            }

            foreach ($aliases as $key) {
                $value = $attributes[$key] ?? null;

                if (!is_scalar($value) || (string)$value === '') {
                    continue;
                }

                $limit = in_array($key, self::STATEMENT_KEYS, true) ? self::STATEMENT_LIMIT : self::VALUE_LIMIT;
                $facts[] = $key . '=' . $this->truncate($this->squish((string)$value), $limit);

                break;
            }
        }

        foreach ($this->exceptions($span) as $exception) {
            $facts[] = $exception;
        }

        return $facts;
    }

    /**
     * The `exception` events, as `type: message`.
     *
     * An exception event is a span saying exactly what went wrong and where,
     * so it is never dropped for the attribute budget — and it is the one
     * place a span carries the same class name the log line's fingerprint was
     * cut from.
     *
     * @param Span $span
     * @return list<string>
     */
    private function exceptions(array $span): array
    {
        $events = is_array($span['events'] ?? null) ? $span['events'] : [];
        $lines = [];

        foreach ($events as $event) {
            if (!is_array($event) || ($event['name'] ?? null) !== 'exception') {
                continue;
            }

            $attributes = is_array($event['attributes'] ?? null) ? $event['attributes'] : [];
            $type = $this->string($attributes, 'exception.type');
            $message = $this->string($attributes, 'exception.message');

            if ($type === '' && $message === '') {
                $lines[] = 'exception';

                continue;
            }

            $lines[] = 'exception=' . $this->truncate(
                    $this->squish(trim($type . ($type !== '' && $message !== '' ? ': ' : '') . $message)),
                    self::STATEMENT_LIMIT,
                );
        }

        return $lines;
    }

    /**
     * @param Span $span
     */
    private function isError(array $span): bool
    {
        return $this->string($span, 'statusCode') === 'Error';
    }

    /**
     * @param Span $span
     */
    private function duration(array $span): float
    {
        $value = $span['durationMs'] ?? 0;

        return is_numeric($value) ? (float)$value : 0.0;
    }

    /**
     * A duration the way the trace pages print it: ms below a second, s above.
     */
    private function formatDuration(float $ms): string
    {
        if ($ms >= 1000) {
            return rtrim(rtrim(number_format($ms / 1000, 2, '.', ''), '0'), '.') . 's';
        }

        if ($ms >= 10) {
            return sprintf('%dms', (int)round($ms));
        }

        return rtrim(rtrim(number_format($ms, 2, '.', ''), '0'), '.') . 'ms';
    }

    /**
     * Read a string off an array, whatever it actually holds.
     *
     * @param array<string, mixed> $values
     */
    private function string(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * Collapse a multi-line value onto one line.
     */
    private function squish(string $value): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $value));
    }

    /**
     * Cut a value down to a budget, saying so where it was cut.
     */
    private function truncate(string $value, int $limit): string
    {
        return mb_strlen($value) <= $limit ? $value : mb_substr($value, 0, $limit) . '…';
    }
}
