<?php

namespace Tests\Support;

use App\Services\Autofix\AyosException;
use App\Services\Autofix\RunDriver;
use App\Services\Autofix\RunStatus;

/**
 * A run driver that records instead of running.
 *
 * There is no HTTP endpoint to fake any more — starting a job is starting a
 * container — so this is what stands in for the platform. It keeps every spec
 * it was handed, which is how the dispatch tests inspect what a run would have
 * been given, and it can be told to fail the way a real platform does.
 */
class FakeRunDriver implements RunDriver
{
    /** @var list<array{spec: string, job_id: string}> */
    public array $started = [];

    /** @var list<string> */
    public array $stopped = [];

    public ?AyosException $failWith = null;

    public ?RunStatus $status = RunStatus::Running;

    public function __construct(public string $runId = 'run-1') {}

    public function start(string $spec, string $jobId): string
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $this->started[] = ['spec' => $spec, 'job_id' => $jobId];

        return $this->runId;
    }

    public function stop(string $runId): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $this->stopped[] = $runId;
    }

    public function status(string $runId): ?RunStatus
    {
        return $this->status;
    }

    /**
     * The spec of the most recent run, decoded.
     *
     * @return array<string, mixed>
     */
    public function lastSpec(): array
    {
        $last = end($this->started);

        return $last === false ? [] : (array) json_decode($last['spec'], true);
    }
}
