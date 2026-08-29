<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Models\ProjectApiKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates by the public half of a project's key pair.
 *
 * Some clients carry no bearer token: they are configured with a single URL —
 * a DSN — and send the credential it holds back in a header or a query
 * parameter of their own naming. That key is public by construction, since it
 * travels in a deploy config and, in a browser, in the page source, so it
 * authorises the one thing it is safe to disclose: writing logs into its own
 * project.
 *
 * The resolved project and key land in the same request attributes the secret
 * key middleware sets, so everything downstream is unchanged.
 */
class AuthenticatePublicKey
{
    /**
     * The header and parameter the credential arrives in.
     *
     * Both spellings are the wire protocol's, not ours: a client that builds
     * its own requests from a DSN cannot be told to use another name.
     */
    public const AUTH_HEADER = 'X-Sentry-Auth';

    /**
     * The query parameter a browser client passes the key in instead.
     */
    public const KEY_PARAMETER = 'sentry_key';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $publicKey = self::keyFromRequest($request);

        if ($publicKey === null) {
            return $this->unauthorized('Public key missing.');
        }

        $apiKey = ProjectApiKey::findByPublicKey($publicKey);

        if (! $apiKey?->project instanceof Project) {
            return $this->unauthorized('Public key invalid.');
        }

        $request->attributes->set(AuthenticateProjectApiKey::API_KEY_ATTRIBUTE, $apiKey);
        $request->attributes->set(AuthenticateProjectApiKey::PROJECT_ATTRIBUTE, $apiKey->project);

        $apiKey->markAsUsed();

        return $next($request);
    }

    /**
     * Read the public key from the request.
     *
     * Public and static because the ingest rate limiter needs it before this
     * middleware has run, exactly as it does for a secret key (routes.md).
     *
     * The project id the client puts in the path is deliberately not
     * consulted: the project is the one the key belongs to and nothing else
     * (SCHEMA.md R2).
     */
    public static function keyFromRequest(Request $request): ?string
    {
        $key = self::fromAuthHeader((string) $request->header(self::AUTH_HEADER, ''))
            ?? self::fromAuthHeader((string) $request->header('Authorization', ''))
            ?? $request->query(self::KEY_PARAMETER);

        if (! is_string($key)) {
            return null;
        }

        $key = trim($key);

        return $key === '' ? null : $key;
    }

    /**
     * Pull the key out of a comma separated auth header.
     *
     * The header reads `Sentry sentry_version=7, sentry_key=…, sentry_client=…`
     * with the parts in no guaranteed order and the value sometimes quoted, so
     * it is matched rather than parsed positionally.
     */
    private static function fromAuthHeader(string $header): ?string
    {
        if ($header === '' || preg_match('/'.self::KEY_PARAMETER.'\s*=\s*"?([^,"\s]+)"?/i', $header, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Build the JSON response returned for missing or invalid keys.
     */
    protected function unauthorized(string $message): JsonResponse
    {
        return new JsonResponse(['message' => $message], Response::HTTP_UNAUTHORIZED);
    }
}
