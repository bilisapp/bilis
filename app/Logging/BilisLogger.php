<?php

namespace App\Logging;

use Monolog\Handler\NullHandler;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Level;
use Monolog\Logger;

/**
 * The `custom` driver factory behind the `bilis` log channel.
 *
 * Builds a Monolog logger that ships to a Bilis instance, or an inert one when
 * the endpoint or the API key is missing — the channel is then safe to leave in
 * a stack on a machine that has no Bilis configured.
 */
class BilisLogger
{
    /**
     * @param  array<string, mixed>  $config  The `bilis` channel config.
     */
    public function __invoke(array $config): Logger
    {
        $endpoint = trim((string) ($config['endpoint'] ?? ''));
        $apiKey = trim((string) ($config['api_key'] ?? ''));

        if ($endpoint === '' || $apiKey === '') {
            return new Logger('bilis', [new NullHandler]);
        }

        $service = $config['service'] ?? null;

        $handler = new BilisHandler(
            endpoint: $this->ingestEndpoint($endpoint),
            apiKey: $apiKey,
            level: $this->level($config['level'] ?? null),
            timeout: (float) ($config['timeout'] ?? 2.0),
            maxBufferSize: (int) ($config['max_buffer_size'] ?? 500),
            service: is_string($service) && $service !== '' ? $service : null,
        );

        /*
         * Under FPM and Octane the response is already on its way by the time
         * terminating callbacks run, so the ingest call costs the user nothing.
         * Flushing an empty buffer is a no-op, so this never double sends.
         */
        app()->terminating(static fn () => $handler->flush());

        /*
         * A handler that throws would take the whole logging stack with it.
         * The handler already swallows transport failures; this is the belt to
         * that pair of braces.
         */
        return new Logger('bilis', [new WhatFailureGroupHandler([$handler])]);
    }

    /**
     * Resolve the simple JSON ingest URL from the configured Bilis base URL.
     *
     * Existing installs that still point directly at the v1 ingest route are
     * accepted as-is, but new configuration should only store the origin.
     */
    private function ingestEndpoint(string $endpoint): string
    {
        $endpoint = rtrim($endpoint, '/');

        if (str_ends_with($endpoint, '/api/v1/ingest')) {
            return $endpoint;
        }

        return $endpoint.'/api/v1/ingest';
    }

    /**
     * Read the configured minimum level, defaulting to debug.
     *
     * An unknown value is a typo in someone's env file, not a reason to take
     * the application down, so it quietly falls back to the widest level.
     */
    private function level(mixed $level): Level
    {
        if ($level instanceof Level) {
            return $level;
        }

        if (is_int($level)) {
            return Level::tryFrom($level) ?? Level::Debug;
        }

        if (is_string($level)) {
            foreach (Level::cases() as $case) {
                if (strtolower($case->getName()) === strtolower(trim($level))) {
                    return $case;
                }
            }
        }

        return Level::Debug;
    }
}
