<?php

namespace App\Services\Alerting;

/**
 * The identity of an alert instance's label set.
 */
final class Labels
{
    /**
     * A stable hash of a label set, independent of key order: the key of
     * `alert_instances (alert_rule_id, labels_hash)`. Changing it re-keys every
     * instance, so treat a change as a migration.
     *
     * @param  array<string, string>  $labels
     */
    public static function hash(array $labels): string
    {
        ksort($labels, SORT_STRING);

        return sha1(json_encode((object) $labels, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
