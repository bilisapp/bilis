<?php

use App\Providers\AppServiceProvider;
use App\Services\Autofix\LocalRunDriver;
use App\Services\Autofix\RunDriver;
use App\Services\Autofix\ScalewayRunDriver;

/**
 * Drop the fake the base test case installs and bind the real drivers again.
 */
function bindRealRunDriver(string $environment): void
{
    app()->forgetInstance(RunDriver::class);
    app()->detectEnvironment(fn (): string => $environment);

    (fn () => $this->configureAutofixRunner())->call(new AppServiceProvider(app()));
}

test('the local runner is used outside production', function () {
    config(['autofix.runner.driver' => 'local']);
    bindRealRunDriver('local');

    expect(app(RunDriver::class))->toBeInstanceOf(LocalRunDriver::class);
});

test('production refuses the local runner unless an operator opted in', function () {
    config(['autofix.runner.driver' => 'local']);
    bindRealRunDriver('production');

    expect(fn () => app(RunDriver::class))->toThrow(RuntimeException::class, 'disabled in production');

    config(['autofix.runner.allow_local_in_production' => true]);

    expect(app(RunDriver::class))->toBeInstanceOf(LocalRunDriver::class);
});

test('production uses the scaleway runner when configured', function () {
    config(['autofix.runner.driver' => 'scaleway']);
    bindRealRunDriver('production');

    expect(app(RunDriver::class))->toBeInstanceOf(ScalewayRunDriver::class);
});

test('a mistyped driver is an error, never a silent local run', function () {
    config(['autofix.runner.driver' => 'scalway']);
    bindRealRunDriver('local');

    expect(fn () => app(RunDriver::class))->toThrow(RuntimeException::class, 'Unknown Autofix runner driver "scalway"');
});
