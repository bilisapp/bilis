<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Models\ProjectApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers cross-origin requests on the ingest endpoints a browser can reach.
 *
 * A client running in a page cannot post anywhere without the server's
 * permission, and the browser asks for it either up front — a preflight
 * `OPTIONS` carrying no credentials of its own — or by refusing to hand the
 * response back unless the reply names its origin.
 *
 * Permission is per project, from `projects.allowed_origins`. An unconfigured
 * project answers no browser at all: the request may still reach the
 * application, but without these headers the browser discards the response and
 * the script that made it never sees anything.
 */
class HandleEnvelopeCors
{
    /**
     * How long a browser may cache the preflight, in seconds.
     */
    private const MAX_AGE = 3600;

    /**
     * The headers a client is allowed to send, when it asks for none itself.
     */
    private const DEFAULT_ALLOWED_HEADERS = 'Content-Type, X-Sentry-Auth, X-Requested-With';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $origin = trim((string) $request->headers->get('Origin', ''));

        /*
         * Vary on Origin whatever the answer is. The reply differs per origin,
         * and a cache that does not know that would hand one site's allowed
         * response to another site's request.
         */
        $response->headers->set('Vary', 'Origin', false);

        if ($origin === '' || ! $this->project($request)?->allowsOrigin($origin)) {
            return $response;
        }

        /*
         * The origin is echoed rather than answered with `*`, even when the
         * project allows everything: an echo is what a client sending
         * credentials needs, and a wildcard would forbid that later without
         * anything here changing.
         */
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Expose-Headers', 'Retry-After');

        if ($request->isMethod('OPTIONS')) {
            $requested = trim((string) $request->headers->get('Access-Control-Request-Headers', ''));

            $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', $requested === '' ? self::DEFAULT_ALLOWED_HEADERS : $requested);
            $response->headers->set('Access-Control-Max-Age', (string) self::MAX_AGE);
        }

        return $response;
    }

    /**
     * The project the request is for, if it can be established.
     *
     * On a POST the public key middleware has already resolved it. A preflight
     * carries no headers of its own, so the key is read from the query string
     * — which is exactly why a browser client puts it there — and looked up
     * here. There is no authentication decision in this: an unknown key means
     * no origin is allowed, not that the request is rejected.
     */
    private function project(Request $request): ?Project
    {
        $project = AuthenticateProjectApiKey::project($request);

        if ($project instanceof Project) {
            return $project;
        }

        $publicKey = AuthenticatePublicKey::keyFromRequest($request);

        return $publicKey === null ? null : ProjectApiKey::findByPublicKey($publicKey)?->project;
    }
}
