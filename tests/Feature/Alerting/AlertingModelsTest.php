<?php

use App\Enums\AlertCondition;
use App\Enums\AlertEventType;
use App\Enums\AlertState;
use App\Enums\NoDataState;
use App\Enums\NotificationChannelType;
use App\Models\AlertEvent;
use App\Models\AlertInstance;
use App\Models\AlertRule;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Services\Alerting\AlertEvaluationSettings;
use App\Services\Alerting\AlertStateMachine;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('builds a rule with instances, events and channels through the factories', function () {
    $team = Team::factory()->create();
    $rule = AlertRule::factory()->forTeam($team)->metrics()->create();
    $channel = NotificationChannel::factory()->for($team)->slack()->create();
    $rule->notificationChannels()->attach($channel);

    $instance = AlertInstance::factory()->for($rule, 'rule')->firing()->withLabels(['host.name' => 'web-1'])->create();
    $event = AlertEvent::factory()->forRule($rule)->for($instance, 'instance')->create(['labels' => $instance->labels]);

    // The factory keys the event by the labels it was saved with.
    expect($event->dedupe_key)->toBe('rule:'.$rule->id.':'.$instance->labels_hash.':firing:'.$event->occurred_at->getTimestamp());

    $rule->refresh();

    expect($team->alertRules()->sole()->is($rule))->toBeTrue()
        ->and($team->notificationChannels()->sole()->is($channel))->toBeTrue()
        ->and($rule->condition)->toBe(AlertCondition::GreaterThan)
        ->and($rule->threshold)->toBe(0.9)
        ->and($rule->no_data_state)->toBe(NoDataState::Firing)
        ->and($rule->query['group_by'])->toBe('host.name')
        ->and($rule->notificationChannels->sole()->is($channel))->toBeTrue()
        ->and($channel->alertRules->sole()->is($rule))->toBeTrue()
        ->and($rule->instances->sole()->state)->toBe(AlertState::Firing)
        ->and($rule->instances->sole()->labels)->toBe(['host.name' => 'web-1'])
        ->and($rule->events->sole()->type)->toBe(AlertEventType::Firing)
        ->and($rule->events->sole()->instance->is($instance))->toBeTrue()
        ->and(AlertEventType::from('issue_regressed'))->toBe(AlertEventType::IssueRegressed);
});

it('encrypts channel config at rest and hides it from serialisation', function () {
    $channel = NotificationChannel::factory()->webhook()->create();

    $raw = DB::table('notification_channels')->where('id', $channel->id)->value('config');

    expect($channel->fresh()->config['secret'])->toStartWith('whsec_')
        ->and($channel->type)->toBe(NotificationChannelType::Webhook)
        ->and($raw)->not->toContain('whsec_')
        ->and($raw)->not->toContain('https://')
        ->and($channel->toArray())->not->toHaveKey('config');
});

it('allows one instance per label set per rule', function () {
    $rule = AlertRule::factory()->create();
    AlertInstance::factory()->for($rule, 'rule')->withLabels(['service.name' => 'checkout'])->create();

    AlertInstance::factory()->for($rule, 'rule')->withLabels(['service.name' => 'checkout'])->create();
})->throws(UniqueConstraintViolationException::class);

it('records an event once per dedupe key', function () {
    AlertEvent::factory()->create(['dedupe_key' => 'rule:1:abc:firing:1762603200']);

    AlertEvent::factory()->create(['dedupe_key' => 'rule:1:abc:firing:1762603200']);
})->throws(UniqueConstraintViolationException::class);

it('derives factory dedupe keys from the final attributes', function () {
    $rule = AlertRule::factory()->create();

    $resolved = AlertEvent::factory()->forRule($rule)->resolved()->create();
    $web1 = AlertEvent::factory()->forRule($rule)->create(['labels' => ['service.name' => 'web-1']]);
    $web2 = AlertEvent::factory()->forRule($rule)->create(['labels' => ['service.name' => 'web-2']]);

    expect($resolved->dedupe_key)->toContain(':resolved:')
        ->and($web1->dedupe_key)->not->toBe($web2->dedupe_key)
        ->and(AlertEvent::factory()->create(['dedupe_key' => 'kept'])->dedupe_key)->toBe('kept');
});

it('makes more alert instances than there are demo service names', function () {
    $rule = AlertRule::factory()->create();

    expect(AlertInstance::factory()->for($rule, 'rule')->count(12)->create())->toHaveCount(12);
});

it('keeps history when the rule and instance are deleted', function () {
    $rule = AlertRule::factory()->create();
    $instance = AlertInstance::factory()->for($rule, 'rule')->create();
    $event = AlertEvent::factory()->forRule($rule)->for($instance, 'instance')->create();

    $rule->delete();

    expect(AlertInstance::query()->count())->toBe(0)
        ->and($event->fresh()->alert_rule_id)->toBeNull()
        ->and($event->fresh()->alert_instance_id)->toBeNull();
});

it('round-trips an instance through the state machine', function () {
    $rule = AlertRule::factory()->create(['threshold' => 25, 'for_seconds' => 0]);
    $instance = AlertInstance::factory()->for($rule, 'rule')->create();
    $at = CarbonImmutable::parse('2026-11-08 12:00:00', 'UTC');

    $result = AlertStateMachine::next($instance->toState(), 40.0, AlertEvaluationSettings::fromRule($rule), $at);
    $instance->applyState($result->state)->save();

    expect($result->transition)->toBe(AlertEventType::Firing)
        ->and($instance->fresh()->state)->toBe(AlertState::Firing)
        ->and($instance->fresh()->fired_at->equalTo($at))->toBeTrue()
        ->and($instance->fresh()->toState()->firedAt?->equalTo($at))->toBeTrue();
});

it('indexes the foreign keys a deleted rule or instance nulls', function () {
    $indexed = collect(Schema::getIndexes('alert_events'))->pluck('columns')->all();

    expect($indexed)->toContain(['alert_rule_id', 'occurred_at'])
        ->and($indexed)->toContain(['alert_instance_id']);
});
