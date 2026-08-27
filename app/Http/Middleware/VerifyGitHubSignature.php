<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a webhook delivery from the Bilis GitHub App.
 *
 * GitHub signs the raw body with the App's webhook secret, so that is what is
 * checked here — the parsed payload is never trusted to reproduce the bytes
 * that were signed.
 *
 * A missing secret answers 503 rather than 401 on purpose: it is this
 * application that is misconfigured, not the caller, and GitHub retries a 5xx
 * delivery. A 401 would tell GitHub the delivery was rejected on its merits
 * and quietly drop events that were, in fact, perfectly good.
 */
class VerifyGitHubSignature
{
    /**
     * The header carrying the body signature.
     */
    public const SIGNATURE_HEADER = 'X-Hub-Signature-256';

    /**
     * The prefix naming the digest algorithm in the signature header.
     */
    public const SIGNATURE_PREFIX = 'sha256=';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('autofix.github.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            return new JsonResponse(
                ['message' => 'GitHub webhooks are not configured.'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $signature = $request->header(self::SIGNATURE_HEADER);

        if (! is_string($signature) || ! $this->matches($signature, $request->getContent(), $secret)) {
            return new JsonResponse(['message' => 'Invalid signature.'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }

    /**
     * Sign a body the way GitHub does, for tests and for documentation.
     */
    public static function signature(string $body, string $secret): string
    {
        return self::SIGNATURE_PREFIX.hash_hmac('sha256', $body, $secret);
    }

    /**
     * Determine whether the signature header matches the body.
     */
    protected function matches(string $signature, string $body, string $secret): bool
    {
        $signature = trim($signature);

        if (! str_starts_with($signature, self::SIGNATURE_PREFIX)) {
            return false;
        }

        return hash_equals(self::signature($body, $secret), $signature);
    }
}
