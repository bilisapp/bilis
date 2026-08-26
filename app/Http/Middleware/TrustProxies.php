<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

/**
 * Trust forwarded headers only from the addresses configured for this instance.
 *
 * The default is still everything, which is right behind a reverse proxy that
 * is the only route into the container. Where the app port is reachable from
 * somewhere else, an unfiltered `X-Forwarded-For` is a client-controlled IP
 * address — and that address is what the rate limiter and the audit trail key
 * on — so `TRUSTED_PROXIES` narrows it.
 *
 * The list is read per request rather than pinned at boot, so it cannot be
 * clobbered by the order in which the kernel and the providers resolve.
 */
class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for the application.
     *
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        $proxies = config('security.trusted_proxies', '*');

        if (! is_string($proxies) || trim($proxies) === '' || trim($proxies) === '*') {
            return '*';
        }

        return array_values(array_filter(array_map('trim', explode(',', $proxies))));
    }
}
