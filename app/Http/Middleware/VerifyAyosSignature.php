<?php

namespace App\Http\Middleware;

use App\Models\FixJob;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a callback from an Ayos run.
 *
 * There is no shared secret any more. Bilis mints an Ed25519 keypair per job,
 * injects the private half into that one run and keeps the public half on the
 * job row; a run signs everything it posts, and this verifies it against the
 * key belonging to the job it claims to be. A key recovered from a compromised
 * container therefore authenticates exactly one job — which is already over —
 * rather than every job in both directions forever.
 *
 * The signed string is unchanged: `{timestamp}.{raw body}`, with the timestamp
 * inside the digest so a captured body cannot be replayed under a fresh one.
 * Only the primitive moved, from HMAC-SHA256 to Ed25519.
 *
 * **The job id is read from a body that has not been verified yet.** That is
 * unavoidable — it is what selects the key — and it is safe only because it is
 * used for exactly one thing: a lookup. Nothing else in the request is trusted,
 * and nothing is written, until the signature checks out. The controller
 * re-validates the payload from scratch afterwards.
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
     * The prefix naming the scheme in the signature header.
     *
     * `sha256=` named the retired shared-secret HMAC. Renaming it rather than
     * reusing it means an old caller fails closed and legibly, instead of
     * having its digest compared against a key of a different kind.
     */
    public const SIGNATURE_PREFIX = 'ed25519=';

    /**
     * How far, in seconds, a timestamp may sit from now in either direction.
     */
    public const TOLERANCE_SECONDS = 300;

    /**
     * The request attribute the verified job is left on, so the controller does
     * not have to look it up a second time.
     */
    public const JOB_ATTRIBUTE = 'ayos.job';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $timestamp = $request->header(self::TIMESTAMP_HEADER);

        if (! is_string($timestamp) || ! $this->isFresh($timestamp)) {
            return $this->unauthorized();
        }

        $signature = $request->header(self::SIGNATURE_HEADER);

        if (! is_string($signature) || ! str_starts_with(trim($signature), self::SIGNATURE_PREFIX)) {
            return $this->unauthorized();
        }

        $body = $request->getContent();
        $job = $this->claimedJob($body);

        if ($job === null || ! is_string($job->ayos_public_key) || $job->ayos_public_key === '') {
            return $this->unauthorized();
        }

        if (! $this->matches($signature, $timestamp, $body, $job->ayos_public_key)) {
            return $this->unauthorized();
        }

        $request->attributes->set(self::JOB_ATTRIBUTE, $job);

        return $next($request);
    }

    /**
     * The job this request claims to be for.
     *
     * Parsed from an unverified body, and used for nothing but selecting the
     * key to verify that body with.
     */
    protected function claimedJob(string $body): ?FixJob
    {
        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return null;
        }

        $jobId = $payload['job_id'] ?? null;

        if (! is_string($jobId) || $jobId === '') {
            return null;
        }

        return FixJob::query()->where('uuid', $jobId)->first();
    }

    /**
     * Determine whether the signature verifies against the job's public key.
     */
    protected function matches(string $signature, string $timestamp, string $body, string $publicKey): bool
    {
        $raw = base64_decode(substr(trim($signature), strlen(self::SIGNATURE_PREFIX)), true);
        $key = base64_decode(trim($publicKey), true);

        if ($raw === false || $key === false) {
            return false;
        }

        if (strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($raw, trim($timestamp).'.'.$body, $key);
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
     * nothing about which half of the check it failed, nor whether the job id
     * it guessed exists.
     */
    protected function unauthorized(): JsonResponse
    {
        return new JsonResponse(['message' => 'Invalid signature.'], Response::HTTP_UNAUTHORIZED);
    }
}
