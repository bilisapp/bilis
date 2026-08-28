<?php

namespace Tests;

use App\Services\Autofix\RunDriver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use Tests\Support\FakeRunDriver;

abstract class TestCase extends BaseTestCase
{
    /**
     * Never let a test start a real Ayos run.
     *
     * Dispatching a fix job spawns a container — a real one, which clones a
     * repository, calls a model and posts back. A test that reaches the
     * dispatch path without saying so would do all of that against whatever
     * credentials the machine happens to hold, and leave a process running
     * after the suite had finished. Binding a recording driver by default
     * makes spawning something a test has to opt into, via `fakeRuns()`.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(RunDriver::class, new FakeRunDriver);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
