<?php

use App\Services\Logs\LogFilters;
use Illuminate\Http\Request;

/**
 * The log viewer's "Reset" control returns the filters to the window the page
 * opens on. That default is stated twice — once on the server, once in
 * `resources/js/lib/logs.ts` as `DEFAULT_RANGE_PRESET` — and the two only stay
 * honest by agreement. If they drift, Reset quietly stops resetting: the
 * dropdown would show one window while the server answered with another.
 */
function viewerDefaultRangeMinutes(): int
{
    $source = file_get_contents(resource_path('js/lib/logs.ts'));

    expect($source)->toBeString();

    preg_match(
        "/export const DEFAULT_RANGE_PRESET: LogRangePreset = '([^']+)'/",
        (string) $source,
        $preset,
    );

    expect($preset[1] ?? null)->not->toBeNull(
        'DEFAULT_RANGE_PRESET is missing from resources/js/lib/logs.ts.',
    );

    preg_match(
        "/\{\s*value: '".preg_quote($preset[1], '/')."',[^}]*minutes: (\d+)\s*\}/",
        (string) $source,
        $minutes,
    );

    expect($minutes[1] ?? null)->not->toBeNull(
        "The '{$preset[1]}' preset is missing from RANGE_PRESETS.",
    );

    return (int) $minutes[1];
}

test('the viewer default window matches the server default window', function () {
    expect(viewerDefaultRangeMinutes())->toBe(LogFilters::DEFAULT_RANGE_MINUTES);
});

test('a request with no window falls back to the default range', function () {
    $filters = LogFilters::fromRequest(new Request);

    expect((int) $filters->from->diffInMinutes($filters->to))
        ->toBe(LogFilters::DEFAULT_RANGE_MINUTES)
        ->and($filters->project)->toBeNull()
        ->and($filters->service)->toBeNull()
        ->and($filters->search)->toBeNull()
        ->and($filters->severities)->toBe([]);
});
