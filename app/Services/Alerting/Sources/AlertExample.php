<?php

namespace App\Services\Alerting\Sources;

/**
 * One distinct thing behind a rule's reading — a log message, a span name —
 * and how much of the window it accounts for (see {@see AlertSource::examples()}).
 *
 * Stored in an alert event's `context['examples']` as {@see toArray()}, so a
 * notification rendered later, or after the rows have expired, still says
 * what was caught.
 */
final readonly class AlertExample
{
    /**
     * @param  string  $title  the message or span name, trimmed to one line
     * @param  int  $count  matching rows in the window
     * @param  string|null  $detail  a short qualifier: `3 failed · p95 1.2 s`
     * @param  string|null  $traceId  one request to open, when the rows carry one
     */
    public function __construct(
        public string $title,
        public int $count,
        public ?string $service = null,
        public ?string $detail = null,
        public ?string $traceId = null,
    ) {}

    /**
     * @return array{title: string, count: int, service: string|null, detail: string|null, trace_id: string|null}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'count' => $this->count,
            'service' => $this->service,
            'detail' => $this->detail,
            'trace_id' => $this->traceId,
        ];
    }

    /**
     * Back from an event's context; null for anything that is not one.
     */
    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value) || ! is_string($value['title'] ?? null) || ! is_numeric($value['count'] ?? null)) {
            return null;
        }

        return new self(
            title: $value['title'],
            count: (int) $value['count'],
            service: is_string($value['service'] ?? null) && $value['service'] !== '' ? $value['service'] : null,
            detail: is_string($value['detail'] ?? null) && $value['detail'] !== '' ? $value['detail'] : null,
            traceId: is_string($value['trace_id'] ?? null) && $value['trace_id'] !== '' ? $value['trace_id'] : null,
        );
    }

    /**
     * The title as one line of at most `$width` characters.
     */
    public static function oneLine(string $text, int $width = 160): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', strtok(trim($text), "\n") ?: ''));

        return mb_strimwidth($line, 0, $width, '…');
    }
}
