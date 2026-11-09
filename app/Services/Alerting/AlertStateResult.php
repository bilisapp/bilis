<?php

namespace App\Services\Alerting;

use App\Enums\AlertEventType;

/**
 * The state machine's answer: the instance's new state, and the transition to
 * record and notify — `Firing` or `Resolved` — when there is one.
 */
final readonly class AlertStateResult
{
    public function __construct(
        public AlertInstanceState $state,
        public ?AlertEventType $transition = null,
    ) {}
}
