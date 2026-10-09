<?php

namespace App\Services\Tools;

/**
 * The free public tools, in the order the index lists them.
 *
 * One list feeds the /tools index, the "more tools" strip on every tool page
 * and the sitemap, so adding a tool here is what makes it discoverable.
 */
final class ToolCatalog
{
    /**
     * @return list<array{route: string, name: string, summary: string}>
     */
    public static function all(): array
    {
        return [
            [
                'route' => 'tools.log-cost',
                'name' => 'Log volume & cost calculator',
                'summary' => 'Events per second to GB a day, a per-GB SaaS bill, and the disk the same logs need on your own box.',
            ],
            [
                'route' => 'tools.log-levels',
                'name' => 'Log levels, mapped',
                'summary' => 'Syslog, PSR-3, Python, Log4j, slog, pino, .NET and Ruby levels side by side, on the OpenTelemetry severity scale.',
            ],
            [
                'route' => 'tools.traceparent',
                'name' => 'Traceparent decoder',
                'summary' => 'Decode, validate or generate a W3C Trace Context traceparent header.',
            ],
            [
                'route' => 'tools.timestamp',
                'name' => 'Timestamp converter',
                'summary' => 'Unix seconds, milliseconds, microseconds and nanoseconds to dates and back — OTLP and ClickHouse formats included.',
            ],
        ];
    }

    /**
     * Every tool except the one being shown.
     *
     * @return list<array{route: string, name: string, summary: string}>
     */
    public static function except(string $route): array
    {
        return array_values(array_filter(self::all(), fn (array $tool): bool => $tool['route'] !== $route));
    }
}
