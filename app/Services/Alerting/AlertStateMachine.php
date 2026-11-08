<?php

namespace App\Services\Alerting;

use App\Enums\AlertEventType;
use App\Enums\AlertState;
use App\Enums\NoDataState;
use Carbon\CarbonImmutable;

/**
 * One evaluation step for one alert instance. Pure: no database, no clock.
 *
 * Prometheus' semantics (rules/alerting.go):
 *
 * - condition true: inactive → pending (`activeAt` = now); pending → firing
 *   once `now − activeAt ≥ for`, which with `for = 0` is the same evaluation.
 *   Emits `Firing`. A firing instance whose condition is true again forgets
 *   any `keepFiringSince`.
 * - condition false: pending → inactive, silently; firing → inactive once the
 *   condition has been false for `keep_firing_for` (at once when it is 0).
 *   Emits `Resolved`.
 *
 * No value for a known instance is Grafana's no-data case: `NoDataState`
 * treats it as false, as true, or leaves the instance exactly as it was.
 * Elapsed times compare in whole seconds, and exactly equal counts as elapsed.
 */
final class AlertStateMachine
{
    /**
     * Advance an instance by one evaluation.
     *
     * @param  float|null  $value  the series' value this evaluation; null when it had no data
     */
    public static function next(
        AlertInstanceState $previous,
        ?float $value,
        AlertEvaluationSettings $settings,
        CarbonImmutable $evaluatedAt,
    ): AlertStateResult {
        $conditionHolds = match (true) {
            $value !== null => $settings->condition->matches($value, $settings->threshold),
            $settings->noDataState === NoDataState::Firing => true,
            $settings->noDataState === NoDataState::Inactive => false,
            default => null,
        };

        if ($conditionHolds === null) {
            return new AlertStateResult($previous);
        }

        return $conditionHolds
            ? self::whenTrue($previous, $settings, $evaluatedAt)
            : self::whenFalse($previous, $settings, $evaluatedAt);
    }

    /**
     * The condition holds: start or continue the `for` wait, or keep firing.
     */
    private static function whenTrue(
        AlertInstanceState $previous,
        AlertEvaluationSettings $settings,
        CarbonImmutable $evaluatedAt,
    ): AlertStateResult {
        if ($previous->state === AlertState::Firing) {
            return new AlertStateResult(new AlertInstanceState(
                state: AlertState::Firing,
                activeAt: $previous->activeAt,
                firedAt: $previous->firedAt,
                keepFiringSince: null,
                resolvedAt: $previous->resolvedAt,
            ));
        }

        $activeAt = $previous->state === AlertState::Pending && $previous->activeAt !== null
            ? $previous->activeAt
            : $evaluatedAt;

        if (self::secondsBetween($activeAt, $evaluatedAt) >= $settings->forSeconds) {
            return new AlertStateResult(new AlertInstanceState(
                state: AlertState::Firing,
                activeAt: $activeAt,
                firedAt: $evaluatedAt,
                keepFiringSince: null,
                resolvedAt: null,
            ), AlertEventType::Firing);
        }

        return new AlertStateResult(new AlertInstanceState(
            state: AlertState::Pending,
            activeAt: $activeAt,
            firedAt: $previous->firedAt,
            keepFiringSince: null,
            resolvedAt: $previous->resolvedAt,
        ));
    }

    /**
     * The condition does not hold: drop a pending wait, or resolve once
     * `keep_firing_for` has run out.
     */
    private static function whenFalse(
        AlertInstanceState $previous,
        AlertEvaluationSettings $settings,
        CarbonImmutable $evaluatedAt,
    ): AlertStateResult {
        if ($previous->state === AlertState::Inactive) {
            return new AlertStateResult($previous);
        }

        if ($previous->state === AlertState::Pending) {
            return new AlertStateResult(new AlertInstanceState(
                state: AlertState::Inactive,
                firedAt: $previous->firedAt,
                resolvedAt: $previous->resolvedAt,
            ));
        }

        $falseSince = $previous->keepFiringSince ?? $evaluatedAt;

        if (self::secondsBetween($falseSince, $evaluatedAt) < $settings->keepFiringForSeconds) {
            return new AlertStateResult(new AlertInstanceState(
                state: AlertState::Firing,
                activeAt: $previous->activeAt,
                firedAt: $previous->firedAt,
                keepFiringSince: $falseSince,
                resolvedAt: $previous->resolvedAt,
            ));
        }

        return new AlertStateResult(new AlertInstanceState(
            state: AlertState::Inactive,
            firedAt: $previous->firedAt,
            resolvedAt: $evaluatedAt,
        ), AlertEventType::Resolved);
    }

    /**
     * Whole seconds from one moment to a later one.
     */
    private static function secondsBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return $to->getTimestamp() - $from->getTimestamp();
    }
}
