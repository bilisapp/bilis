<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attach the response security headers, including a nonce based CSP.
 *
 * The nonce is generated before the response is rendered and handed to the
 * Vite helper, so `@vite` and `@fonts` stamp it onto every tag they emit; the
 * handful of hand written inline tags in the layouts read it from `$cspNonce`.
 */
class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        view()->share('cspNonce', $nonce);

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $response->headers->set('Referrer-Policy', (string) config('security.referrer_policy'));
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->remove('X-Powered-By');

        if ($permissionsPolicy = (string) config('security.permissions_policy')) {
            $response->headers->set('Permissions-Policy', $permissionsPolicy);
        }

        if ($strictTransportSecurity = $this->strictTransportSecurity($request)) {
            $response->headers->set('Strict-Transport-Security', $strictTransportSecurity);
        }

        if (config('security.csp.enabled') && $this->shouldSendPolicy($response) && ! $this->devServerIsUnexpressible()) {
            $response->headers->set(
                config('security.csp.report_only')
                    ? 'Content-Security-Policy-Report-Only'
                    : 'Content-Security-Policy',
                $this->contentSecurityPolicy($nonce, $request),
            );
        }

        return $response;
    }

    /**
     * Whether this response is a document the policy has anything to say about.
     *
     * JSON — an Inertia partial, an API error — is never parsed as markup, and
     * a redirect has no body at all.
     */
    protected function shouldSendPolicy(Response $response): bool
    {
        $contentType = (string) $response->headers->get('Content-Type', '');

        return $contentType === '' || Str::contains($contentType, 'text/html');
    }

    /**
     * Build the policy for this request.
     */
    protected function contentSecurityPolicy(string $nonce, Request $request): string
    {
        $directives = [
            'default-src' => ["'self'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
            'frame-ancestors' => ["'none'"],
            'form-action' => ["'self'"],
            'manifest-src' => ["'self'"],
            'media-src' => ["'self'"],
            'worker-src' => ["'self'", 'blob:'],

            // `strict-dynamic` is what makes the nonce worth having: the entry
            // module can import its own chunks, and nothing else gets in, host
            // allow-lists included.
            'script-src' => ["'self'", "'nonce-{$nonce}'", "'strict-dynamic'"],

            // Vue writes `style` attributes for popover and menu positioning,
            // which a nonce cannot cover. Scripts get no such exemption.
            'style-src' => ["'self'", "'unsafe-inline'"],

            'img-src' => ["'self'", 'data:', 'blob:'],
            'font-src' => ["'self'", 'data:'],
            'connect-src' => ["'self'"],
            'frame-src' => ["'none'"],
        ];

        if ($this->isHorizonDashboard($request)) {
            $directives['script-src'][] = "'unsafe-eval'";
        }

        foreach ($this->configuredSources() as $directive => $sources) {
            $directives[$directive] = [...$directives[$directive], ...$sources];
        }

        foreach ($this->developmentSources() as $directive => $sources) {
            $directives[$directive] = [...$directives[$directive], ...$sources];
        }

        $policy = [];

        foreach ($directives as $directive => $sources) {
            $policy[] = $directive.' '.implode(' ', array_values(array_unique($sources)));
        }

        if ($request->isSecure()) {
            $policy[] = 'upgrade-insecure-requests';
        }

        if ($reportUri = config('security.csp.report_uri')) {
            $policy[] = 'report-uri '.$reportUri;
        }

        return implode('; ', $policy);
    }

    /**
     * Whether this request is for Horizon's own dashboard.
     *
     * Horizon mounts an in-DOM template (`<div id="horizon">` with its
     * `<router-view>`), so Vue compiles that markup at runtime with
     * `new Function` — an `EvalError` under any policy without `'unsafe-eval'`,
     * and not something a nonce can cover. The exception is scoped to the
     * dashboard's own routes rather than granted to the application: nothing
     * Bilis ships evaluates a string, and Horizon is already behind the
     * `viewHorizon` gate.
     */
    protected function isHorizonDashboard(Request $request): bool
    {
        $path = trim((string) config('horizon.path'), '/');

        return $path !== '' && $request->is($path, $path.'/*');
    }

    /**
     * Extra origins from configuration, plus the analytics host if one is set.
     *
     * @return array<string, array<int, string>>
     */
    protected function configuredSources(): array
    {
        $sources = [];

        foreach ((array) config('security.csp.allow', []) as $key => $value) {
            $origins = array_values(array_filter(preg_split('/[\s,]+/', (string) $value) ?: []));

            if ($origins !== []) {
                $sources[$key.'-src'] = $origins;
            }
        }

        if ($analytics = $this->analyticsOrigin()) {
            $sources['script-src'] = [...$sources['script-src'] ?? [], $analytics];
            $sources['connect-src'] = [...$sources['connect-src'] ?? [], $analytics];
        }

        /*
         * The autofix event stream is the one connection a page opens to a
         * host that is not this instance: the browser watches a running job on
         * Ayos directly, because proxying a long-lived SSE/WebSocket through
         * Octane would pin a worker per viewer. Only the origin is allowed,
         * and only when one is configured — a stock install adds nothing.
         */
        foreach ($this->ayosStreamOrigins() as $origin) {
            $sources['connect-src'] = [...$sources['connect-src'] ?? [], $origin];
        }

        return $sources;
    }

    /**
     * The Ayos stream origin, plus its WebSocket form, when one is configured.
     *
     * Both schemes are listed because the spec leaves SSE and WebSocket open
     * to Ayos, and a `wss:` connection is not covered by an `https:` source.
     *
     * @return list<string>
     */
    protected function ayosStreamOrigins(): array
    {
        $origin = $this->origin((string) config('autofix.ayos.stream_url'));

        if ($origin === null) {
            return [];
        }

        return [$origin, Str::replaceStart('http', 'ws', $origin)];
    }

    /**
     * The origin serving the analytics script, when it is not this instance.
     *
     * The tag itself carries the nonce, so this only matters for the beacon
     * the script sends back — and for browsers that ignore `strict-dynamic`.
     */
    protected function analyticsOrigin(): ?string
    {
        return $this->origin((string) config('bilis.analytics.script_url'));
    }

    /**
     * Reduce a configured URL to the scheme/host/port a CSP source can name.
     *
     * A CSP host-source has no form for a path, so everything after the
     * authority is dropped rather than emitted as an unparseable source.
     */
    protected function origin(string $url): ?string
    {
        if (trim($url) === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $port = parse_url($url, PHP_URL_PORT);

        return (is_string($scheme) ? $scheme.'://' : '').$host.(is_int($port) ? ':'.$port : '');
    }

    /**
     * The Vite dev server origin, while it is running.
     *
     * @return array<string, array<int, string>>
     */
    protected function developmentSources(): array
    {
        $origin = $this->devServerOrigin();

        if ($origin === null) {
            return [];
        }

        $websocket = Str::replaceStart('http', 'ws', $origin);

        return [
            'script-src' => [$origin],
            'style-src' => [$origin],
            'connect-src' => [$origin, $websocket],
            'font-src' => [$origin],
            'img-src' => [$origin],
        ];
    }

    /**
     * The origin written to the hot file, while the Vite dev server is running.
     */
    protected function devServerOrigin(): ?string
    {
        if (! Vite::isRunningHot()) {
            return null;
        }

        $origin = rtrim(trim((string) @file_get_contents(Vite::hotFile())), '/');

        return $origin === '' ? null : $origin;
    }

    /**
     * Whether the running dev server cannot be named in a policy at all.
     *
     * A CSP host-source has no form for an IPv6 literal, so a dev server that
     * binds to `http://[::1]:5173` cannot be allowed: the source is dropped as
     * unparseable and every asset it serves is blocked. Rather than hand a
     * developer a silently broken page, the policy stands down for the local
     * dev server — production, and the tests, are unaffected.
     *
     * Bind the dev server to a hostname (`server.host: 'localhost'` in
     * `vite.config.ts`) to exercise the real policy while developing.
     */
    protected function devServerIsUnexpressible(): bool
    {
        $origin = $this->devServerOrigin();

        return $origin !== null && str_contains($origin, '[');
    }

    /**
     * The HSTS header value, or null when it must not be sent.
     */
    protected function strictTransportSecurity(Request $request): ?string
    {
        if (! config('security.hsts.enabled') || ! $request->isSecure()) {
            return null;
        }

        $value = 'max-age='.(int) config('security.hsts.max_age');

        if (config('security.hsts.include_subdomains')) {
            $value .= '; includeSubDomains';
        }

        if (config('security.hsts.preload')) {
            $value .= '; preload';
        }

        return $value;
    }
}
