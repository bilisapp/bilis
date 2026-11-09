<?php

namespace Tests\Support;

use App\Models\AlertRule;
use App\Services\Alerting\Sources\AlertReading;
use App\Services\Alerting\Sources\AlertSource;
use Carbon\CarbonImmutable;
use Closure;
use Throwable;

/**
 * An alert source that answers whatever the test tells it to, and remembers
 * what it was asked. Register it with
 * `config(['alerting.sources' => ['fake' => FakeAlertSource::class]])` and bind
 * one instance with `app()->instance(FakeAlertSource::class, $fake)`.
 */
class FakeAlertSource implements AlertSource
{
    /** @var list<array{labels: array<string, string>, value: float}> */
    public array $series = [];

    /** whether the reading says series were left out */
    public bool $truncated = false;

    public ?Throwable $throw = null;

    /** runs inside {@see evaluate()}, before it answers — to change the world mid-read */
    public ?Closure $during = null;

    /** @var array<string, mixed> what {@see rules()} answers */
    public array $rules = [];

    /** @var list<array{labels: array<string, string>, points: list<array{0: int, 1: float|null}>}>|null null: one flat line at 1.0 per bucket */
    public ?array $previewLines = null;

    /** @var list<array{project_ids: list<string>, from: CarbonImmutable, to: CarbonImmutable, step: int}> */
    public array $previewCalls = [];

    /** @var list<array{project_ids: list<string>, from: CarbonImmutable, to: CarbonImmutable}> */
    public array $calls = [];

    public function evaluate(AlertRule $rule, array $projectIds, CarbonImmutable $from, CarbonImmutable $to): AlertReading
    {
        $this->calls[] = ['project_ids' => $projectIds, 'from' => $from, 'to' => $to];

        if ($this->during !== null) {
            ($this->during)();
        }

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return new AlertReading($this->series, $this->truncated);
    }

    public function preview(AlertRule $rule, array $projectIds, CarbonImmutable $from, CarbonImmutable $to, int $stepSeconds): array
    {
        $this->previewCalls[] = ['project_ids' => $projectIds, 'from' => $from, 'to' => $to, 'step' => $stepSeconds];

        if ($this->throw !== null) {
            throw $this->throw;
        }

        if ($this->previewLines !== null) {
            return $this->previewLines;
        }

        $points = [];

        for ($at = $from->getTimestamp(); $at < $to->getTimestamp(); $at += $stepSeconds) {
            $points[] = [$at, 1.0];
        }

        return [['labels' => [], 'points' => $points]];
    }

    public function rules(): array
    {
        return $this->rules;
    }
}
