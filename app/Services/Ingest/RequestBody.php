<?php

namespace App\Services\Ingest;

use Illuminate\Http\Request;

/**
 * Reads an ingest request body, decompressing it when the client says it is
 * compressed.
 *
 * Nothing in front of the application does this: neither a reverse proxy nor
 * FrankenPHP decompresses a request body, so without this an export sent with
 * `Content-Encoding: gzip` — the default of the Collector's `otlphttp`
 * exporter — reaches the mapper as bytes no JSON parser can read, and the
 * whole batch is silently counted as skipped.
 */
class RequestBody
{
    /**
     * Encodings this application can undo.
     *
     * `zstd`, `snappy`, `lz4` and `br` are all things a Collector can be
     * configured to send and PHP has no core function for; they answer 415
     * with this list rather than pretending to accept the data.
     *
     * @var array<int, string>
     */
    public const SUPPORTED_ENCODINGS = ['', 'identity', 'gzip', 'x-gzip', 'deflate'];

    /**
     * The `Content-Encoding` of the request, lowercased and trimmed.
     */
    public static function encoding(Request $request): string
    {
        return strtolower(trim((string) $request->header('Content-Encoding', '')));
    }

    /**
     * Whether the request's encoding can be undone here.
     */
    public static function isSupportedEncoding(string $encoding): bool
    {
        return in_array($encoding, self::SUPPORTED_ENCODINGS, true);
    }

    /**
     * The decoded request body, or null when it cannot be decompressed.
     *
     * A null is a payload problem, not a transport one: callers hand it to the
     * mapper as an unreadable body, which is counted and answered with the
     * usual success status (ingest.md), never a 400.
     */
    public static function read(Request $request): ?string
    {
        $body = $request->getContent();
        $encoding = self::encoding($request);

        if ($encoding === '' || $encoding === 'identity') {
            return $body;
        }

        if ($body === '') {
            return '';
        }

        $limit = self::limit();

        $inflated = match ($encoding) {
            'gzip', 'x-gzip' => @gzdecode($body, $limit),
            // `deflate` is zlib-wrapped by the letter of the HTTP spec and raw
            // in a good deal of the wild; try both before giving up.
            'deflate' => @gzuncompress($body, $limit) ?: @gzinflate($body, $limit),
            default => false,
        };

        if (! is_string($inflated)) {
            return null;
        }

        /*
         * gzdecode() truncates at the limit rather than failing, so a body
         * that fills it exactly is treated as one that did not fit: a
         * compression bomb must not become a half-parsed batch.
         */
        return strlen($inflated) >= $limit ? null : $inflated;
    }

    /**
     * The most bytes a decompressed body may occupy.
     */
    private static function limit(): int
    {
        $limit = (int) config('bilis.ingest.max_decompressed_bytes');

        return $limit > 0 ? $limit : 32 * 1024 * 1024;
    }
}
