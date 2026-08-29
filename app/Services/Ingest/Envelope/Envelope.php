<?php

namespace App\Services\Ingest\Envelope;

/**
 * A parsed envelope: the newline delimited container error reporting clients
 * batch their events in.
 *
 * One JSON header line, then a repeating pair of an item header line and that
 * item's payload. An item header may declare the payload's `length` in bytes,
 * in which case the payload is read by length and may itself contain newlines;
 * without one the payload runs to the next newline.
 *
 * @see https://develop.sentry.dev/sdk/data-model/envelopes/ for the format
 */
class Envelope
{
    /**
     * The largest number of items a single envelope may carry.
     *
     * A client sends a handful; a much longer list is a malformed body being
     * walked one byte at a time, not a client with a lot to say.
     */
    private const MAX_ITEMS = 512;

    /**
     * @param  array<string, mixed>  $header
     * @param  array<int, EnvelopeItem>  $items
     */
    public function __construct(
        public readonly array $header = [],
        public readonly array $items = [],
        public readonly bool $malformed = false,
    ) {}

    /**
     * Parse an envelope body, returning a malformed envelope when it cannot be
     * read.
     *
     * Nothing here throws or rejects: an unreadable body is counted by the
     * caller and answered with the usual success status (ingest.md).
     */
    public static function parse(?string $body): self
    {
        if ($body === null || trim($body) === '') {
            return new self(malformed: true);
        }

        $offset = 0;
        $header = self::decodeLine(self::readLine($body, $offset));

        if ($header === null) {
            return new self(malformed: true);
        }

        $items = [];
        $length = strlen($body);

        while ($offset < $length && count($items) < self::MAX_ITEMS) {
            $itemHeaderLine = self::readLine($body, $offset);

            // A trailing newline after the last item leaves an empty line here.
            if (trim($itemHeaderLine) === '') {
                continue;
            }

            $itemHeader = self::decodeLine($itemHeaderLine);

            if ($itemHeader === null) {
                return new self($header, $items, malformed: true);
            }

            $items[] = new EnvelopeItem(
                type: is_string($itemHeader['type'] ?? null) ? $itemHeader['type'] : '',
                header: $itemHeader,
                payload: self::readPayload($body, $offset, $itemHeader['length'] ?? null),
            );
        }

        return new self($header, $items);
    }

    /**
     * The items of the given type, in the order the client sent them.
     *
     * @return array<int, EnvelopeItem>
     */
    public function itemsOfType(string $type): array
    {
        return array_values(array_filter($this->items, fn (EnvelopeItem $item): bool => $item->type === $type));
    }

    /**
     * Read up to the next newline, advancing past it.
     */
    private static function readLine(string $body, int &$offset): string
    {
        $end = strpos($body, "\n", $offset);

        if ($end === false) {
            $line = substr($body, $offset);
            $offset = strlen($body);

            return $line;
        }

        $line = substr($body, $offset, $end - $offset);
        $offset = $end + 1;

        return $line;
    }

    /**
     * Read one item payload, by declared length when the header gives one.
     *
     * A `length` longer than what is left is clamped rather than refused: the
     * point is to salvage the events the client did send, not to police a body
     * it may have had truncated in transit.
     */
    private static function readPayload(string $body, int &$offset, mixed $declaredLength): string
    {
        if (! is_int($declaredLength) && ! (is_string($declaredLength) && ctype_digit($declaredLength))) {
            return rtrim(self::readLine($body, $offset), "\r");
        }

        $length = max(0, min((int) $declaredLength, strlen($body) - $offset));
        $payload = substr($body, $offset, $length);
        $offset += $length;

        // The payload is followed by a newline that is not part of it.
        if (($body[$offset] ?? '') === "\n") {
            $offset++;
        }

        return $payload;
    }

    /**
     * Decode one JSON line into an array, or null when it is not one.
     *
     * @return array<string, mixed>|null
     */
    private static function decodeLine(string $line): ?array
    {
        $decoded = json_decode(trim($line), true);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }
}
