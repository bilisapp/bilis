<?php

namespace App\Services\Ingest\Envelope;

/**
 * One item of an envelope: its header, and the raw payload bytes.
 *
 * The payload is kept as bytes because not every item type is JSON — an
 * attachment is arbitrary content, and a minidump is binary. Only the types
 * Bilis stores are decoded.
 */
class EnvelopeItem
{
    /**
     * @param  array<string, mixed>  $header
     */
    public function __construct(
        public readonly string $type,
        public readonly array $header,
        public readonly string $payload,
    ) {}

    /**
     * The payload decoded as a JSON object, or null when it is not one.
     *
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        $decoded = json_decode($this->payload, true);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }
}
