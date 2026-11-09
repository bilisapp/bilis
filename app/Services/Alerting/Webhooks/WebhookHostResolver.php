<?php

namespace App\Services\Alerting\Webhooks;

use Illuminate\Container\Attributes\Bind;

/**
 * Resolve a webhook host to every address a connection could use.
 *
 * An interface so a test can answer for DNS. {@see SafeWebhookUrl} checks
 * every address returned here, then pins the connection to one of them.
 */
#[Bind(DnsWebhookHostResolver::class)]
interface WebhookHostResolver
{
    /**
     * IPv4 addresses first, then IPv6; empty when the host does not resolve.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
