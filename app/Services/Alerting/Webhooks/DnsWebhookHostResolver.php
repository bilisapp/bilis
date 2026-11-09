<?php

namespace App\Services\Alerting\Webhooks;

/**
 * A and AAAA records straight from DNS via `dns_get_record()`.
 *
 * `/etc/hosts` is deliberately not consulted: the connection is pinned to the
 * address returned here, so a hosts-file entry can never be reached through it.
 */
final class DnsWebhookHostResolver implements WebhookHostResolver
{
    public function resolve(string $host): array
    {
        $addresses = [];

        foreach ([[DNS_A, 'ip'], [DNS_AAAA, 'ipv6']] as [$type, $field]) {
            foreach (@dns_get_record($host, $type) ?: [] as $record) {
                if (isset($record[$field]) && is_string($record[$field])) {
                    $addresses[] = $record[$field];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
