<?php

namespace App\Services\ClickHouse;

/**
 * Renders PHP values the way ClickHouse reads them as `{name:Type}` parameters.
 *
 * The one implementation of the quoting, so every query that binds a list of
 * strings escapes them identically. The values are bound, never spliced into
 * SQL text; this only spells an `Array(String)` parameter's value.
 */
final class ClickHouseParameters
{
    /**
     * A list of strings as an `Array(String)` parameter value.
     *
     * Service names and the like come from customer telemetry, so the
     * escaping is load-bearing: a service called `a'b` must be matched, not
     * executed.
     *
     * @param  list<string>  $values
     */
    public static function stringArray(array $values): string
    {
        return '['.implode(',', array_map(
            fn (string $value): string => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'",
            $values,
        )).']';
    }
}
