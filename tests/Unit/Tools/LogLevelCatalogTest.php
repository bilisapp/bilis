<?php

use App\Services\Ingest\LogSeverity;
use App\Services\Logs\SeverityLevel;
use App\Services\Tools\LogLevelCatalog;

it('maps every listed level through the ingest alias table', function () {
    foreach (LogLevelCatalog::SYSTEMS as $key => $system) {
        foreach ($system['levels'] as [$name]) {
            expect(LogSeverity::numberForText($name))->not->toBeNull("{$key} level {$name} is not an ingest alias");
        }
    }
});

it('orders every system from least to most severe', function () {
    foreach ((new LogLevelCatalog)->systems() as $key => $system) {
        $numbers = array_column($system['levels'], 'severityNumber');
        $sorted = $numbers;
        sort($sorted);

        expect($numbers)->toBe($sorted, "{$key} is out of order");
    }
});

it('looks a level up by name, by OTel number and by native value', function () {
    $catalog = new LogLevelCatalog;

    $warning = $catalog->lookup('WARNING');
    expect($warning['asText']['severityNumber'])->toBe(13)
        ->and($warning['asText']['bucket'])->toBe(SeverityLevel::Warn);

    $seventeen = $catalog->lookup('17');
    expect($seventeen['asNumber']['severityText'])->toBe('ERROR');

    $forty = $catalog->lookup('40');
    expect(array_column($forty['native'], 'system'))->toBe(['Python — logging', 'Node.js — pino'])
        ->and(array_column($forty['native'], 'severityText'))->toBe(['ERROR', 'WARN']);
});

it('finds nothing for an unknown level', function () {
    $lookup = (new LogLevelCatalog)->lookup('shouting');

    expect($lookup['asText'])->toBeNull()
        ->and($lookup['asNumber'])->toBeNull()
        ->and($lookup['native'])->toBe([]);
});
