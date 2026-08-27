<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a callback from Ayos.
 *
 * Ayos holds no session and no API key: both directions of the control plane
 * are authenticated with one shared secret, an HMAC-SHA256 in
 * `X-Ayos-Signature` over `{timestamp}.{raw body}` and an `X-Ayos-Timestamp`
 * inside a ±5 minute window. The timestamp is inside the signed string on
 * purpose: signing the body alone would let a captured body and signature be
 * replayed forever under a freshly minted timestamp.
 */
class VerifyAyosSignature
{
    /**
     * The header carrying the body signature.
     */
    public const SIGNATURE_HEADER = 'X-Ayos-Signature';

    /**
     * The header carrying the time the request was signed at.
     */
    public const TIMESTAMP_HEADER = 'X-Ayos-Timestamp';

    /**
     * The prefix naming the digest algorithm in the signature header.
     */
    public const SIGNATURE_PREFIX = 'sha256=';

    /**
     * How far, in seconds, a timestamp may sit from now in either direction.
     */
    public const TOLERANCE_SECONDS = 300;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('autofix.ayos.shared_secret');

        if (! is_string($secret) || $secret === '') {
            return $this->unauthorized();
        }

        $timestamp = $request->header(self::TIMESTAMP_HEADER);

        if (! is_string($timestamp) || ! $this->isFresh($timestamp)) {
            return $this->unauthorized();
        }

        $signature = $request->header(self::SIGNATURE_HEADER);

        if (! is_string($signature) || ! $this->matches($signature, $timestamp, $request->getContent(), $secret)) {
            return $this->unauthorized();
        }

        return $next($request);
    }

    /**
     * Sign a request the way Ayos is expected to, for outgoing calls and tests.
     *
     * The signed string is the timestamp, a dot, then the raw body — the
     * timestamp is bound to the digest so it cannot be swapped for a fresh one.
     */
    public static function signature(string $timestamp, string $body, string $secret): string
    {
        return self::SIGNATURE_PREFIX.hash_hmac('sha256', trim($timestamp).'.'.$body, $secret);
    }

    /**
     * Determine whether the signature header matches the timestamp and body.
     */
    protected function matches(string $signature, string $timestamp, string $body, string $secret): bool
    {
        $signature = trim($signature);

        if (! str_starts_with($signature, self::SIGNATURE_PREFIX)) {
            return false;
        }

        return hash_equals(self::signature($timestamp, $body, $secret), $signature);
    }

    /**
     * Determine whether the signing timestamp is inside the replay window.
     */
    protected function isFresh(string $timestamp): bool
    {
        $timestamp = trim($timestamp);

        if ($timestamp === '' || ! ctype_digit(ltrim($timestamp, '-'))) {
            return false;
        }

        return abs(now()->getTimestamp() - (int) $timestamp) <= self::TOLERANCE_SECONDS;
    }

    /**
     * Build the response returned for every rejection.
     *
     * The reason is deliberately not named: an unauthenticated caller learns
     * nothing about which half of the check it failed.
     */
    protected function unauthorized(): JsonResponse
    {
        return new JsonResponse(['message' => 'Invalid signature.'], Response::HTTP_UNAUTHORIZED);
    }
}
