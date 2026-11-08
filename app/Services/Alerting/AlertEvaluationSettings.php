<?php

namespace App\Services\Alerting;

use App\Enums\AlertCondition;
use App\Enums\NoDataState;
use App\Models\AlertRule;

/**
 * The slice of a rule the state machine needs, so the machine never touches a model.
 */
final readonly class AlertEvaluationSettings
{
    public function __construct(
        public AlertCondition $condition,
        public float $threshold,
        public int $forSeconds = 0,
        public int $keepFiringForSeconds = 0,
        public NoDataState $noDataState = NoDataState::Inactive,
    ) {}

    /**
     * Read the settings off a rule.
     */
    public static function fromRule(AlertRule $rule): self
    {
        return new self(
            condition: $rule->condition,
            threshold: $rule->threshold,
            forSeconds: $rule->for_seconds,
            keepFiringForSeconds: $rule->keep_firing_for_seconds,
            noDataState: $rule->no_data_state,
        );
    }
}
