<?php

use App\Enums\AlertCondition;
use App\Enums\AlertEventType;
use App\Enums\AlertRuleHealth;
use App\Enums\AlertState;
use App\Enums\NoDataState;
use App\Jobs\EvaluateAlertRule;
use App\Models\AlertEvent;
use App\Models\AlertInstance;
use App\Models\AlertRule;
use App\Models\Project;
use App\Models\Team;
use App\Services\Alerting\AlertNotifier;
use App\Services\Alerting\Sources\AlertSourceUnavailable;
use Carbon\CarbonImmutable;
use Tests\Support\FakeAlertSource;

/**
 * A notifier that remembers what it was handed instead of sending it.
 */
class RecordingAlertNotifier extends AlertNotifier
{
    /** @var list<AlertEvent> */
    public array $events = [];

    public function notify(AlertEvent $event): void
    {
        $this->events[] = $event;
    }
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00:37', 'UTC'));

    // 11:59:00 — now minus the 60 s lag, aligned to the minute.
    $this->at = CarbonImmutable::parse('2026-11-08 11:59:00', 'UTC')->getTimestamp();

    config(['alerting.sources' => ['fake' => FakeAlertSource::class]]);
    $this->source = new FakeAlertSource;
    app()->instance(FakeAlertSource::class, $this->source);

    $this->notifier = new RecordingAlertNotifier;
    app()->instance(AlertNotifier::class, $this->notifier);

    $this->team = Team::factory()->create();
    $this->project = Project::factory()->forTeam($this->team)->create();
    $this->rule = AlertRule::factory()->forTeam($this->team)->create([
        'source' => 'fake',
        'project_ids' => [$this->project->id],
        'condition' => AlertCondition::GreaterThan,
        'threshold' => 25,
        'window_seconds' => 300,
    ]);
});

function evaluate(AlertRule $rule, int $at): void
{
    EvaluateAlertRule::dispatchSync($rule->id, $at);
}

it('reads the closed-open window ending at the evaluation timestamp', function () {
    evaluate($this->rule, $this->at);

    $call = $this->source->calls[0];

    expect($call['to']->getTimestamp())->toBe($this->at)
        ->and($call['from']->getTimestamp())->toBe($this->at - 300)
        ->and($call['project_ids'])->toBe([(string) $this->project->id]);
});

it('fires once and records the event exactly once, even when the evaluation runs again', function () {
    $this->source->series = [['labels' => ['service.name' => 'checkout'], 'value' => 40.0]];

    evaluate($this->rule, $this->at);
    evaluate($this->rule, $this->at);

    $instance = AlertInstance::query()->sole();
    $event = AlertEvent::query()->sole();

    expect($instance->state)->toBe(AlertState::Firing)
        ->and($instance->value)->toBe(40.0)
        ->and($instance->fired_at->getTimestamp())->toBe($this->at)
        ->and($instance->last_seen_with_data_at->getTimestamp())->toBe($this->at)
        ->and($event->type)->toBe(AlertEventType::Firing)
        ->and($event->alert_instance_id)->toBe($instance->id)
        ->and($event->team_id)->toBe($this->team->id)
        ->and($event->labels)->toBe(['service.name' => 'checkout'])
        ->and($event->value)->toBe(40.0)
        ->and($event->threshold)->toBe(25.0)
        ->and($event->condition)->toBe($this->rule->condition)
        ->and($event->severity)->toBe($this->rule->severity)
        ->and($event->occurred_at->getTimestamp())->toBe($this->at)
        ->and($event->dedupe_key)->toBe('rule:'.$this->rule->id.':'.$instance->labels_hash.':firing:'.$this->at)
        ->and($event->context['window'])->toBe(['from' => '2026-11-08T11:54:00Z', 'to' => '2026-11-08T11:59:00Z'])
        ->and($event->context['no_data'])->toBeFalse()
        ->and($this->notifier->events)->toHaveCount(1)
        ->and($this->notifier->events[0]->is($event))->toBeTrue()
        ->and($this->rule->fresh()->health)->toBe(AlertRuleHealth::Ok);
});

it('neither duplicates nor re-notifies an event whose dedupe key already exists', function () {
    $this->source->series = [['labels' => ['service.name' => 'checkout'], 'value' => 40.0]];

    evaluate($this->rule, $this->at);

    // As if the first run's instance write had been lost but its event kept.
    AlertInstance::query()->update(['state' => AlertState::Inactive, 'fired_at' => null, 'active_at' => null]);

    evaluate($this->rule, $this->at);

    expect(AlertEvent::query()->count())->toBe(1)
        ->and(AlertInstance::query()->sole()->state)->toBe(AlertState::Firing)
        ->and($this->notifier->events)->toHaveCount(1);
});

it('waits out `for` in pending before firing', function () {
    $this->rule->update(['for_seconds' => 60]);
    $this->source->series = [['labels' => [], 'value' => 40.0]];

    evaluate($this->rule, $this->at);

    expect(AlertInstance::query()->sole()->state)->toBe(AlertState::Pending)
        ->and(AlertEvent::query()->count())->toBe(0);

    evaluate($this->rule, $this->at + 60);

    $instance = AlertInstance::query()->sole();

    expect($instance->state)->toBe(AlertState::Firing)
        ->and($instance->active_at->getTimestamp())->toBe($this->at)
        ->and($instance->fired_at->getTimestamp())->toBe($this->at + 60)
        ->and(AlertEvent::query()->sole()->type)->toBe(AlertEventType::Firing)
        ->and($this->notifier->events)->toHaveCount(1);
});

it('resolves a firing instance whose value came back under the threshold', function () {
    $instance = AlertInstance::factory()->for($this->rule, 'rule')->firing()->withLabels(['service.name' => 'checkout'])->create();
    $this->source->series = [['labels' => ['service.name' => 'checkout'], 'value' => 3.0]];

    evaluate($this->rule, $this->at);

    $instance->refresh();
    $event = AlertEvent::query()->sole();

    expect($instance->state)->toBe(AlertState::Inactive)
        ->and($instance->resolved_at->getTimestamp())->toBe($this->at)
        ->and($instance->value)->toBe(3.0)
        ->and($event->type)->toBe(AlertEventType::Resolved)
        ->and($event->value)->toBe(3.0)
        ->and($this->notifier->events)->toHaveCount(1);
});

it('treats a known series without data as its rule\'s no-data state says', function (NoDataState $noData, AlertState $from, AlertState $to, ?AlertEventType $event) {
    $this->rule->update(['no_data_state' => $noData]);
    $factory = AlertInstance::factory()->for($this->rule, 'rule')->withLabels(['service.name' => 'checkout']);
    $instance = ($from === AlertState::Firing ? $factory->firing() : $factory)->create();

    evaluate($this->rule, $this->at);

    expect($instance->refresh()->state)->toBe($to);

    if ($event === null) {
        expect(AlertEvent::query()->count())->toBe(0);
    } else {
        $recorded = AlertEvent::query()->sole();

        expect($recorded->type)->toBe($event)
            ->and($recorded->value)->toBeNull()
            ->and($recorded->context['no_data'])->toBeTrue();
    }
})->with([
    'inactive resolves a firing series' => [NoDataState::Inactive, AlertState::Firing, AlertState::Inactive, AlertEventType::Resolved],
    'firing fires a silent series' => [NoDataState::Firing, AlertState::Inactive, AlertState::Firing, AlertEventType::Firing],
    'keep_last leaves it firing' => [NoDataState::KeepLast, AlertState::Firing, AlertState::Firing, null],
]);

it('leaves a known series alone when the source says its reading was truncated', function () {
    $this->rule->update(['no_data_state' => NoDataState::Inactive]);
    $instance = AlertInstance::factory()->for($this->rule, 'rule')->firing()->withLabels(['service.name' => 'checkout'])->create();
    $before = $instance->fresh()->toArray();

    // checkout had data, but the source kept only the most extreme series.
    $this->source->series = [['labels' => ['service.name' => 'api'], 'value' => 90.0]];
    $this->source->truncated = true;

    evaluate($this->rule, $this->at);

    expect($instance->fresh()->toArray())->toBe($before)
        ->and(AlertEvent::query()->pluck('type')->all())->toBe([AlertEventType::Firing]);
});

it('writes nothing when a newer evaluation finished while this one was reading', function () {
    $this->source->series = [['labels' => [], 'value' => 40.0]];
    $this->source->during = fn () => AlertRule::query()->whereKey($this->rule->id)
        ->update(['last_evaluated_at' => CarbonImmutable::createFromTimestampUTC($this->at + 60)]);

    evaluate($this->rule, $this->at);

    expect(AlertInstance::query()->count())->toBe(0)
        ->and(AlertEvent::query()->count())->toBe(0)
        ->and($this->rule->fresh()->last_evaluated_at->getTimestamp())->toBe($this->at + 60);
});

it('records an error from a failed job only when no evaluation has recorded this tick or a later one', function () {
    $job = new EvaluateAlertRule($this->rule->id, $this->at);

    // A newer tick has already succeeded: the old job's timeout changes nothing.
    $this->rule->update(['last_evaluated_at' => CarbonImmutable::createFromTimestampUTC($this->at + 60), 'health' => AlertRuleHealth::Ok]);
    $job->failed(new RuntimeException('timed out'));

    expect($this->rule->fresh()->health)->toBe(AlertRuleHealth::Ok)
        ->and($this->rule->fresh()->last_evaluated_at->getTimestamp())->toBe($this->at + 60)
        ->and(AlertEvent::query()->count())->toBe(0);

    // Nothing newer: the rule goes err, once.
    $this->rule->update(['last_evaluated_at' => CarbonImmutable::createFromTimestampUTC($this->at - 60)]);
    $job->failed(new RuntimeException('timed out'));

    expect($this->rule->fresh()->health)->toBe(AlertRuleHealth::Err)
        ->and($this->rule->fresh()->last_error)->toContain('timed out')
        ->and(AlertEvent::query()->sole()->type)->toBe(AlertEventType::RuleError);
});

it('keeps every state and records one rule_error when the source fails', function () {
    $instance = AlertInstance::factory()->for($this->rule, 'rule')->firing()->withLabels(['service.name' => 'checkout'])->create();
    $before = $instance->fresh()->toArray();
    $this->source->throw = new AlertSourceUnavailable('ClickHouse timed out after 10s');

    evaluate($this->rule, $this->at);
    evaluate($this->rule, $this->at + 60);

    $rule = $this->rule->fresh();
    $event = AlertEvent::query()->sole();

    expect($rule->health)->toBe(AlertRuleHealth::Err)
        ->and($rule->last_error)->toBe('ClickHouse timed out after 10s')
        ->and($instance->fresh()->toArray())->toBe($before)
        ->and($event->type)->toBe(AlertEventType::RuleError)
        ->and($event->alert_instance_id)->toBeNull()
        ->and($event->context['error'])->toBe('ClickHouse timed out after 10s')
        ->and($this->notifier->events)->toHaveCount(1);

    // Recovery clears the error and notifies nothing for it.
    $this->source->throw = null;
    $this->source->series = [['labels' => ['service.name' => 'checkout'], 'value' => 90.0]];

    evaluate($this->rule, $this->at + 120);

    expect($this->rule->fresh()->health)->toBe(AlertRuleHealth::Ok)
        ->and($this->rule->fresh()->last_error)->toBeNull()
        ->and(AlertEvent::query()->count())->toBe(1)
        ->and($this->notifier->events)->toHaveCount(1);
});

it('marks a rule with an unregistered source as failing', function () {
    $this->rule->update(['source' => 'nope']);

    evaluate($this->rule, $this->at);

    expect($this->rule->fresh()->health)->toBe(AlertRuleHealth::Err)
        ->and($this->rule->fresh()->last_error)->toContain('nope');
});

it('drops project ids that do not belong to the rule\'s team', function () {
    $foreign = Project::factory()->create();
    $this->rule->update(['project_ids' => [$this->project->id, $foreign->id]]);

    evaluate($this->rule, $this->at);

    expect($this->source->calls[0]['project_ids'])->toBe([(string) $this->project->id]);
});

it('does not read at all when none of the rule\'s projects are the team\'s', function () {
    $this->rule->update(['project_ids' => [Project::factory()->create()->id]]);

    evaluate($this->rule, $this->at);

    expect($this->source->calls)->toBe([])
        ->and($this->rule->fresh()->health)->toBe(AlertRuleHealth::Err)
        ->and(AlertEvent::query()->sole()->type)->toBe(AlertEventType::RuleError);
});

it('reads every project the team has now when project_ids is null, and no other team\'s', function () {
    Project::factory()->create();
    $this->rule->update(['project_ids' => null]);
    $later = Project::factory()->forTeam($this->team)->create();

    evaluate($this->rule, $this->at);

    expect($this->source->calls[0]['project_ids'])->toEqualCanonicalizing([(string) $this->project->id, (string) $later->id]);
});

it('records a rule error for an all-projects rule of a team without projects', function () {
    $this->rule->update(['project_ids' => null]);
    $this->project->delete();

    evaluate($this->rule, $this->at);

    expect($this->source->calls)->toBe([])
        ->and($this->rule->fresh()->health)->toBe(AlertRuleHealth::Err)
        ->and(AlertEvent::query()->sole()->type)->toBe(AlertEventType::RuleError);
});

it('retires, silently, a series firing only on no data for longer than retire_after', function () {
    $this->rule->update(['no_data_state' => NoDataState::Firing]);
    $silent = AlertInstance::factory()->for($this->rule, 'rule')->firing()->withLabels(['host.name' => 'decommissioned'])
        ->create(['last_seen_with_data_at' => now()->subSeconds(86_400 + 120)]);
    $recent = AlertInstance::factory()->for($this->rule, 'rule')->firing()->withLabels(['host.name' => 'rebooting'])
        ->create(['last_seen_with_data_at' => now()->subHour()]);
    $reporting = AlertInstance::factory()->for($this->rule, 'rule')->firing()->withLabels(['host.name' => 'busy'])
        ->create(['last_seen_with_data_at' => now()->subDays(2)]);
    $this->source->series = [['labels' => ['host.name' => 'busy'], 'value' => 40.0]];

    evaluate($this->rule, $this->at);

    expect(AlertInstance::query()->find($silent->id))->toBeNull()
        ->and($recent->fresh()->state)->toBe(AlertState::Firing)
        ->and($reporting->fresh()->state)->toBe(AlertState::Firing)
        ->and(AlertEvent::query()->count())->toBe(0)
        ->and($this->notifier->events)->toBe([]);
});

it('measures a series never seen with data from when it was created, after the configured retire_after', function () {
    config(['alerting.retire_after' => 3600]);
    $this->rule->update(['no_data_state' => NoDataState::Firing]);
    $silent = AlertInstance::factory()->for($this->rule, 'rule')->firing()->withLabels(['host.name' => 'never'])
        ->create(['last_seen_with_data_at' => null, 'created_at' => now()->subHours(2)]);

    evaluate($this->rule, $this->at);

    expect(AlertInstance::query()->find($silent->id))->toBeNull()
        ->and(AlertEvent::query()->count())->toBe(0);
});

it('retires nothing under a rule whose no-data state is not firing', function (NoDataState $noData, array $events) {
    $this->rule->update(['no_data_state' => $noData]);
    AlertInstance::factory()->for($this->rule, 'rule')->firing()->withLabels(['host.name' => 'gone'])
        ->create(['last_seen_with_data_at' => now()->subDays(3)]);

    evaluate($this->rule, $this->at);

    // Inactive resolves it out loud (and the stale-series sweep forgets it, as before); keep_last keeps it firing.
    expect(AlertEvent::query()->pluck('type')->all())->toBe($events);
})->with([
    'inactive resolves it' => [NoDataState::Inactive, [AlertEventType::Resolved]],
    'keep_last keeps it firing' => [NoDataState::KeepLast, []],
]);

it('records events for a muted rule but sends nothing', function () {
    $this->rule->update(['muted_until' => now()->addHour()]);
    $this->source->series = [['labels' => [], 'value' => 40.0]];

    evaluate($this->rule, $this->at);

    expect(AlertEvent::query()->sole()->type)->toBe(AlertEventType::Firing)
        ->and($this->notifier->events)->toBe([]);
});

it('keeps only the most extreme series past max_instances', function (AlertCondition $condition, array $expected) {
    config(['alerting.max_instances' => 2]);
    $this->rule->update(['condition' => $condition]);
    $this->source->series = array_map(
        fn (float $value): array => ['labels' => ['host.name' => 'web-'.(int) $value], 'value' => $value],
        [10.0, 40.0, 30.0, 20.0],
    );

    evaluate($this->rule, $this->at);

    expect(AlertInstance::query()->orderBy('value')->pluck('value')->all())->toBe($expected);
})->with([
    'highest for >' => [AlertCondition::GreaterThan, [30.0, 40.0]],
    'lowest for <' => [AlertCondition::LessThan, [10.0, 20.0]],
]);

it('forgets inactive series that have had no data for a day', function () {
    $stale = AlertInstance::factory()->for($this->rule, 'rule')->withLabels(['host.name' => 'gone'])
        ->create(['last_seen_with_data_at' => now()->subDays(2)]);
    $firing = AlertInstance::factory()->for($this->rule, 'rule')->firing()->withLabels(['host.name' => 'down'])
        ->create(['last_seen_with_data_at' => now()->subDays(2)]);
    $this->rule->update(['no_data_state' => NoDataState::KeepLast]);

    evaluate($this->rule, $this->at);

    expect(AlertInstance::query()->find($stale->id))->toBeNull()
        ->and(AlertInstance::query()->find($firing->id))->not->toBeNull();
});

it('does nothing for a disabled or deleted rule, or an evaluation older than the last', function () {
    $this->source->series = [['labels' => [], 'value' => 40.0]];

    $this->rule->update(['enabled' => false]);
    evaluate($this->rule, $this->at);

    $this->rule->update(['enabled' => true, 'last_evaluated_at' => CarbonImmutable::createFromTimestampUTC($this->at + 60)]);
    evaluate($this->rule, $this->at);

    EvaluateAlertRule::dispatchSync(999_999, $this->at);

    expect($this->source->calls)->toBe([])
        ->and(AlertInstance::query()->count())->toBe(0);
});

it('is unique per rule and evaluation, on the alerts queue, tried once', function () {
    $job = new EvaluateAlertRule(7, $this->at);

    expect($job->uniqueId())->toBe('7:'.$this->at)
        ->and($job->queue)->toBe('alerts')
        ->and($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(20);
});
