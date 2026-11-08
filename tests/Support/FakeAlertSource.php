<?php

namespace Tests\Support;

use App\Models\AlertRule;
use App\Services\Alerting\Sources\AlertSource;
use Carbon\CarbonImmutable;
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

    public ?Throwable $throw = null;

    /** @var array<string, mixed> what {@see rules()} answers */
    public array $rules = [];

    /** @var list<array{labels: array<string, string>, points: list<array{0: int, 1: float|null}>}>|null null: one flat line at 1.0 per bucket */
    public ?array $previewLines = null;

    /** @var list<array{project_ids: list<string>, from: CarbonImmutable, to: CarbonImmutable, step: int}> */
    public array $previewCalls = [];

    /** @var list<array{project_ids: list<string>, from: CarbonImmutable, to: CarbonImmutable}> */
    public array $calls = [];

    public function evaluate(AlertRule $rule, array $projectIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $this->calls[] = ['project_ids' => $projectIds, 'from' => $from, 'to' => $to];

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->series;
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
