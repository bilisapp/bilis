<?php

use App\Enums\AlertCondition;
use App\Enums\AlertEventType;
use App\Enums\AlertState;
use App\Enums\NoDataState;
use App\Services\Alerting\AlertEvaluationSettings;
use App\Services\Alerting\AlertInstanceState;
use App\Services\Alerting\AlertStateMachine;
use Carbon\CarbonImmutable;

/**
 * "value > 10", with the given for / keep_firing_for / no-data handling.
 */
function overTen(int $for = 0, int $keepFiringFor = 0, NoDataState $noData = NoDataState::Inactive): AlertEvaluationSettings
{
    return new AlertEvaluationSettings(AlertCondition::GreaterThan, 10.0, $for, $keepFiringFor, $noData);
}

function at(int $minute): CarbonImmutable
{
    return CarbonImmutable::parse('2026-11-08 12:00:00', 'UTC')->addMinutes($minute);
}

function pendingSince(int $minute): AlertInstanceState
{
    return new AlertInstanceState(AlertState::Pending, activeAt: at($minute));
}

function firingSince(int $minute, ?int $keepFiringSince = null): AlertInstanceState
{
    return new AlertInstanceState(
        AlertState::Firing,
        activeAt: at($minute),
        firedAt: at($minute),
        keepFiringSince: $keepFiringSince === null ? null : at($keepFiringSince),
    );
}

describe('condition true', function () {
    it('moves inactive to pending, stamping activeAt, when for > 0', function () {
        $result = AlertStateMachine::next(AlertInstanceState::inactive(), 11.0, overTen(for: 120), at(0));

        expect($result->transition)->toBeNull()
            ->and($result->state->state)->toBe(AlertState::Pending)
            ->and($result->state->activeAt)->toEqual(at(0))
            ->and($result->state->firedAt)->toBeNull();
    });

    it('fires on the first evaluation when for = 0', function () {
        $result = AlertStateMachine::next(AlertInstanceState::inactive(), 11.0, overTen(), at(0));

        expect($result->transition)->toBe(AlertEventType::Firing)
            ->and($result->state->state)->toBe(AlertState::Firing)
            ->and($result->state->activeAt)->toEqual(at(0))
            ->and($result->state->firedAt)->toEqual(at(0));
    });

    it('stays pending while for has not elapsed', function () {
        $result = AlertStateMachine::next(pendingSince(0), 11.0, overTen(for: 120), at(1));

        expect($result->transition)->toBeNull()
            ->and($result->state->state)->toBe(AlertState::Pending)
            ->and($result->state->activeAt)->toEqual(at(0));
    });

    it('fires when exactly for has elapsed', function () {
        $result = AlertStateMachine::next(pendingSince(0), 11.0, overTen(for: 120), at(2));

        expect($result->transition)->toBe(AlertEventType::Firing)
            ->and($result->state->state)->toBe(AlertState::Firing)
            ->and($result->state->activeAt)->toEqual(at(0))
            ->and($result->state->firedAt)->toEqual(at(2));
    });

    it('does not fire one second before for has elapsed', function () {
        $result = AlertStateMachine::next(pendingSince(0), 11.0, overTen(for: 120), at(2)->subSecond());

        expect($result->transition)->toBeNull()
            ->and($result->state->state)->toBe(AlertState::Pending);
    });

    it('keeps firing without a second firing transition', function () {
        $result = AlertStateMachine::next(firingSince(0), 50.0, overTen(), at(5));

        expect($result->transition)->toBeNull()
            ->and($result->state->state)->toBe(AlertState::Firing)
            ->and($result->state->firedAt)->toEqual(at(0));
    });

    it('forgets keepFiringSince when the condition holds again', function () {
        $result = AlertStateMachine::next(firingSince(0, keepFiringSince: 3), 50.0, overTen(keepFiringFor: 600), at(4));

        expect($result->transition)->toBeNull()
            ->and($result->state->state)->toBe(AlertState::Firing)
            ->and($result->state->keepFiringSince)->toBeNull();
    });

    it('clears an old resolvedAt when it fires again', function () {
        $previous = new AlertInstanceState(AlertState::Inactive, firedAt: at(0), resolvedAt: at(5));

        $result = AlertStateMachine::next($previous, 11.0, overTen(), at(10));

        expect($result->transition)->toBe(AlertEventType::Firing)
            ->and($result->state->firedAt)->toEqual(at(10))
            ->and($result->state->resolvedAt)->toBeNull();
    });
});

describe('condition false', function () {
    it('leaves an inactive instance alone', function () {
        $previous = AlertInstanceState::inactive();

        $result = AlertStateMachine::next($previous, 3.0, overTen(), at(0));

        expect($result->transition)->toBeNull()
            ->and($result->state)->toBe($previous);
    });

    it('drops pending to inactive silently', function () {
        $result = AlertStateMachine::next(pendingSince(0), 3.0, overTen(for: 120), at(1));

        expect($result->transition)->toBeNull()
            ->and($result->state->state)->toBe(AlertState::Inactive)
            ->and($result->state->activeAt)->toBeNull();
    });

    it('resolves a firing instance at once when keep_firing_for = 0', function () {
        $result = AlertStateMachine::next(firingSince(0), 3.0, overTen(), at(5));

        expect($result->transition)->toBe(AlertEventType::Resolved)
            ->and($result->state->state)->toBe(AlertState::Inactive)
            ->and($result->state->activeAt)->toBeNull()
            ->and($result->state->keepFiringSince)->toBeNull()
            ->and($result->state->firedAt)->toEqual(at(0))
            ->and($result->state->resolvedAt)->toEqual(at(5));
    });

    it('keeps firing on the first false evaluation, starting keepFiringSince', function () {
        $result = AlertStateMachine::next(firingSince(0), 3.0, overTen(keepFiringFor: 120), at(5));

        expect($result->transition)->toBeNull()
            ->and($result->state->state)->toBe(AlertState::Firing)
            ->and($result->state->keepFiringSince)->toEqual(at(5));
    });

    it('keeps firing while keep_firing_for has not elapsed', function () {
        $result = AlertStateMachine::next(firingSince(0, keepFiringSince: 5), 3.0, overTen(keepFiringFor: 120), at(6));

        expect($result->transition)->toBeNull()
            ->and($result->state->state)->toBe(AlertState::Firing)
            ->and($result->state->keepFiringSince)->toEqual(at(5));
    });

    it('resolves when exactly keep_firing_for has elapsed', function () {
        $result = AlertStateMachine::next(firingSince(0, keepFiringSince: 5), 3.0, overTen(keepFiringFor: 120), at(7));

        expect($result->transition)->toBe(AlertEventType::Resolved)
            ->and($result->state->state)->toBe(AlertState::Inactive)
            ->and($result->state->keepFiringSince)->toBeNull()
            ->and($result->state->resolvedAt)->toEqual(at(7));
    });
});

describe('no data', function () {
    it('treats no data as false when no_data_state is inactive', function () {
        $settings = overTen(noData: NoDataState::Inactive);

        $resolved = AlertStateMachine::next(firingSince(0), null, $settings, at(5));
        $dropped = AlertStateMachine::next(pendingSince(0), null, overTen(for: 120, noData: NoDataState::Inactive), at(1));

        expect($resolved->transition)->toBe(AlertEventType::Resolved)
            ->and($resolved->state->state)->toBe(AlertState::Inactive)
            ->and($dropped->transition)->toBeNull()
            ->and($dropped->state->state)->toBe(AlertState::Inactive);
    });

    it('treats no data as true when no_data_state is firing', function () {
        $fired = AlertStateMachine::next(AlertInstanceState::inactive(), null, overTen(noData: NoDataState::Firing), at(0));
        $pending = AlertStateMachine::next(AlertInstanceState::inactive(), null, overTen(for: 300, noData: NoDataState::Firing), at(0));

        expect($fired->transition)->toBe(AlertEventType::Firing)
            ->and($fired->state->state)->toBe(AlertState::Firing)
            ->and($pending->transition)->toBeNull()
            ->and($pending->state->state)->toBe(AlertState::Pending);
    });

    it('changes nothing when no_data_state is keep_last', function (AlertInstanceState $previous) {
        $result = AlertStateMachine::next($previous, null, overTen(for: 120, keepFiringFor: 120, noData: NoDataState::KeepLast), at(30));

        expect($result->transition)->toBeNull()
            ->and($result->state)->toBe($previous);
    })->with([
        'inactive' => fn () => AlertInstanceState::inactive(),
        'pending' => fn () => pendingSince(0),
        'firing' => fn () => firingSince(0),
        'firing on borrowed time' => fn () => firingSince(0, keepFiringSince: 5),
    ]);
});

it('runs a full cycle: pending, firing, kept firing, resolved', function () {
    $settings = overTen(for: 120, keepFiringFor: 120);
    $state = AlertInstanceState::inactive();
    $transitions = [];

    foreach ([0 => 20.0, 1 => 20.0, 2 => 20.0, 3 => 1.0, 4 => 1.0, 5 => 1.0] as $minute => $value) {
        $result = AlertStateMachine::next($state, $value, $settings, at($minute));
        $state = $result->state;
        $transitions[$minute] = [$state->state, $result->transition];
    }

    expect($transitions)->toBe([
        0 => [AlertState::Pending, null],
        1 => [AlertState::Pending, null],
        2 => [AlertState::Firing, AlertEventType::Firing],
        3 => [AlertState::Firing, null],
        4 => [AlertState::Firing, null],
        5 => [AlertState::Inactive, AlertEventType::Resolved],
    ]);
});

it('compares with each condition', function (AlertCondition $condition, float $value, bool $expected) {
    expect($condition->matches($value, 10.0))->toBe($expected);
})->with([
    '> above' => [AlertCondition::GreaterThan, 10.5, true],
    '> equal' => [AlertCondition::GreaterThan, 10.0, false],
    '> below' => [AlertCondition::GreaterThan, 9.5, false],
    '>= above' => [AlertCondition::GreaterThanOrEqual, 10.5, true],
    '>= equal' => [AlertCondition::GreaterThanOrEqual, 10.0, true],
    '>= below' => [AlertCondition::GreaterThanOrEqual, 9.5, false],
    '< above' => [AlertCondition::LessThan, 10.5, false],
    '< equal' => [AlertCondition::LessThan, 10.0, false],
    '< below' => [AlertCondition::LessThan, 9.5, true],
    '<= above' => [AlertCondition::LessThanOrEqual, 10.5, false],
    '<= equal' => [AlertCondition::LessThanOrEqual, 10.0, true],
    '<= below' => [AlertCondition::LessThanOrEqual, 9.5, true],
]);

it('evaluates a below-threshold rule through the machine', function () {
    $settings = new AlertEvaluationSettings(AlertCondition::LessThan, 1.0);

    $result = AlertStateMachine::next(AlertInstanceState::inactive(), 0.0, $settings, at(0));

    expect($result->transition)->toBe(AlertEventType::Firing);
});
