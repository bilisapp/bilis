<?php

namespace App\Services\Alerting;

use App\Services\Alerting\Sources\AlertSource;
use App\Services\Alerting\Sources\AlertSourceUnavailable;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves a rule's `source` key to its {@see AlertSource} through
 * `config('alerting.sources')`. Adding an entity means adding a class there.
 */
final class AlertSources
{
    public function __construct(private readonly Container $container) {}

    /**
     * The source registered under a key.
     *
     * @throws AlertSourceUnavailable when the key is not registered
     */
    public function for(string $key): AlertSource
    {
        $class = config('alerting.sources.'.$key);

        if (! is_string($class) || ! is_a($class, AlertSource::class, true)) {
            throw new AlertSourceUnavailable(sprintf('Unknown alert source [%s].', $key));
        }

        return $this->container->make($class);
    }
}
