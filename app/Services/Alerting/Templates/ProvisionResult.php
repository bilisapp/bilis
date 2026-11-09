<?php

namespace App\Services\Alerting\Templates;

/**
 * What one {@see AlertProvisioner::provision()} run changed for a team.
 */
final readonly class ProvisionResult
{
    /**
     * @param  bool  $channelCreated  the "Team email" channel was created this run
     * @param  list<string>  $created  template keys installed this run
     * @param  list<string>  $upgraded  template keys moved to a newer version
     * @param  list<string>  $undetectable  data kinds (`logs` | `traces` | `hosts`) that could not be checked; retried next run
     */
    public function __construct(
        public bool $channelCreated = false,
        public array $created = [],
        public array $upgraded = [],
        public array $undetectable = [],
    ) {}

    /**
     * Whether the run changed anything.
     */
    public function changed(): bool
    {
        return $this->channelCreated || $this->created !== [] || $this->upgraded !== [];
    }
}
