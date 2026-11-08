<?php

namespace App\Services\Alerting;

use App\Enums\AlertState;
use Carbon\CarbonImmutable;

/**
 * The part of an alert instance the state machine reads and writes.
 *
 * `keepFiringSince` is Prometheus' KeepFiringSince: when a firing instance's
 * condition first turned false, so `keep_firing_for` can be measured from it.
 * It is null whenever the instance is not firing on borrowed time.
 */
final readonly class AlertInstanceState
{
    public function __construct(
        public AlertState $state,
        public ?CarbonImmutable $activeAt = null,
        public ?CarbonImmutable $firedAt = null,
        public ?CarbonImmutable $keepFiringSince = null,
        public ?CarbonImmutable $resolvedAt = null,
    ) {}

    /**
     * A series seen for the first time.
     */
    public static function inactive(): self
    {
        return new self(AlertState::Inactive);
    }
}
