<?php

use App\Services\Alerting\EvaluationClock;

it('aligns a moment down to its interval on the unix epoch', function (int $timestamp, int $interval, int $expected) {
    expect(EvaluationClock::align($timestamp, $interval))->toBe($expected);
})->with([
    'mid-minute' => [1_762_598_437, 60, 1_762_598_400],
    'on the slot' => [1_762_598_400, 60, 1_762_598_400],
    'five minutes' => [1_762_598_437, 300, 1_762_598_400],
    'fifteen minutes' => [1_762_599_299, 900, 1_762_598_700],
]);

it('puts the next evaluation strictly after now, even on a slot boundary', function (int $now, int $interval, int $expected) {
    expect(EvaluationClock::nextSlotAfter($now, $interval))->toBe($expected);
})->with([
    'mid-minute' => [1_762_598_437, 60, 1_762_598_460],
    'on the slot' => [1_762_598_400, 60, 1_762_598_460],
    'five minutes' => [1_762_598_437, 300, 1_762_598_700],
]);

it('evaluates at now minus the lag, aligned down', function () {
    // 12:00:37 − 60 s = 11:59:37 → 11:59:00 for a minute rule, 11:55:00 for five.
    $now = 1_762_603_237;

    expect(EvaluationClock::evaluatedAt($now, 60, 60))->toBe(1_762_603_140)
        ->and(EvaluationClock::evaluatedAt($now, 60, 300))->toBe(1_762_602_900)
        ->and(EvaluationClock::evaluatedAt($now, 0, 60))->toBe(1_762_603_200);
});

it('never divides by a zero interval', function () {
    expect(EvaluationClock::align(1_762_598_437, 0))->toBe(1_762_598_437);
});
