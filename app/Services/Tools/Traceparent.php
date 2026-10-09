<?php

namespace App\Services\Tools;

/**
 * A W3C Trace Context `traceparent` header, parsed or generated.
 *
 * `version-traceid-parentid-flags`, all lowercase hex. Version `00` is exactly
 * four fields; a higher version may append fields after them, which a reader
 * must ignore, and `ff` is forbidden. An all-zero trace or parent id is
 * invalid. Bit 0 of the flags is `sampled`; bit 1 is Level 2's `random`
 * (the right-most 7 bytes of the trace id were generated randomly).
 *
 * @see https://www.w3.org/TR/trace-context-2/#traceparent-header
 */
final class Traceparent
{
    private const PATTERN = '/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})(-.*)?$/';

    /**
     * @param  list<string>  $errors
     */
    private function __construct(
        public readonly string $input,
        public readonly ?string $version,
        public readonly ?string $traceId,
        public readonly ?string $parentId,
        public readonly ?string $flags,
        public readonly array $errors,
    ) {}

    /**
     * Parse what someone pasted: the bare value, or a whole `traceparent:` header line.
     */
    public static function parse(string $input): self
    {
        $value = trim($input);
        $value = (string) preg_replace('/^traceparent\s*:\s*/i', '', $value);
        $value = trim($value, " \t\"'");

        if ($value === '') {
            return new self($input, null, null, null, null, ['Paste a traceparent value to decode it.']);
        }

        $lowered = strtolower($value);

        if (preg_match(self::PATTERN, $lowered, $parts) !== 1) {
            return new self($value, null, null, null, null, [self::shapeError($lowered)]);
        }

        [, $version, $traceId, $parentId, $flags] = $parts;
        $extra = $parts[5] ?? '';
        $errors = [];

        if ($value !== $lowered) {
            $errors[] = 'The value contains uppercase hex. The spec allows lowercase only, so a strict receiver drops this header and starts a new trace.';
        }

        if ($version === 'ff') {
            $errors[] = 'Version ff is forbidden by the spec.';
        }

        if ($version === '00' && $extra !== '') {
            $errors[] = 'Version 00 has exactly four fields; this one has more after the flags.';
        }

        if (trim($traceId, '0') === '') {
            $errors[] = 'The trace id is all zeroes, which the spec defines as invalid.';
        }

        if (trim($parentId, '0') === '') {
            $errors[] = 'The parent id is all zeroes, which the spec defines as invalid.';
        }

        return new self($value, $version, $traceId, $parentId, $flags, $errors);
    }

    /**
     * A fresh, valid, sampled header with random ids.
     */
    public static function generate(bool $sampled = true): self
    {
        // Bit 1 (`random`) is honest here: every byte of the trace id is random.
        $flags = sprintf('%02x', ($sampled ? 0x01 : 0x00) | 0x02);

        return self::parse('00-'.self::randomHex(16).'-'.self::randomHex(8).'-'.$flags);
    }

    public function isValid(): bool
    {
        return $this->errors === [] && $this->traceId !== null;
    }

    /**
     * Whether the parts parsed at all, even if a rule about their values failed.
     */
    public function isParsed(): bool
    {
        return $this->traceId !== null;
    }

    public function header(): string
    {
        return implode('-', [$this->version, $this->traceId, $this->parentId, $this->flags]);
    }

    public function isSampled(): bool
    {
        return $this->flags !== null && (hexdec($this->flags) & 0x01) === 0x01;
    }

    public function isRandom(): bool
    {
        return $this->flags !== null && (hexdec($this->flags) & 0x02) === 0x02;
    }

    /**
     * Flag bits set beyond the two the spec defines.
     */
    public function unknownFlagBits(): int
    {
        return $this->flags === null ? 0 : (hexdec($this->flags) & ~0x03 & 0xFF);
    }

    /**
     * Name the first thing wrong with a value that is not shaped like a header.
     */
    private static function shapeError(string $value): string
    {
        $fields = explode('-', $value);

        if (count($fields) < 4) {
            return 'A traceparent has four dash-separated fields — version, trace id, parent id, flags — and this has '.count($fields).'.';
        }

        $expectations = [
            ['version', 2],
            ['trace id', 32],
            ['parent id', 16],
            ['flags', 2],
        ];

        foreach ($expectations as $index => [$name, $length]) {
            $field = $fields[$index];

            if (strlen($field) !== $length) {
                return "The {$name} must be {$length} hex characters; it is ".strlen($field).'.';
            }

            if (! ctype_xdigit($field)) {
                return "The {$name} contains characters that are not hex.";
            }
        }

        return 'This does not parse as a traceparent header.';
    }

    /**
     * @param  int<1, max>  $bytes
     */
    private static function randomHex(int $bytes): string
    {
        do {
            $hex = bin2hex(random_bytes($bytes));
        } while (trim($hex, '0') === '');

        return $hex;
    }
}
