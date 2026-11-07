<?php

use App\Services\Issues\IssueFilters;
use App\Services\Issues\IssueList;

test('every hour of a day window gets its own point', function () {
    $from = 1_762_430_400; // an hour boundary
    $to = $from + 24 * 3600 + 1800; // now, half way through the 25th hour

    $series = [];

    for ($hour = 0; $hour <= 24; $hour++) {
        $series[$from + $hour * 3600] = 1;
    }

    $points = IssueList::sparkline($series, $from, $to, 3600);

    // 25 hourly rows, 25 points, each holding exactly one hour: no doubled spike.
    expect($points)->toHaveCount(25)
        ->and(array_unique($points))->toBe([1]);
});

test('a week window is laid out by the day', function () {
    $from = 1_762_430_400;
    $to = $from + 7 * 86400 + 3600;

    $points = IssueList::sparkline([$from => 3, $from + 3600 => 2, $from + 86400 => 5, $to - 60 => 1], $from, $to, 86400);

    expect($points)->toBe([5, 5, 0, 0, 0, 0, 0, 1]);
});

test('the step is hourly up to two days and daily beyond', function () {
    expect(IssueList::sparklineStep(new IssueFilters(period: '24h')))->toBe(3600)
        ->and(IssueList::sparklineStep(new IssueFilters(period: '7d')))->toBe(86400);
});
