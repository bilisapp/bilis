<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\AlertCondition;
use App\Enums\NoDataState;
use App\Enums\NotificationChannelType;
use App\Http\Requests\Alerts\SaveAlertRuleRequest;
use App\Jobs\ProvisionTeamAlerts;
use App\Models\AlertRule;
use App\Models\NotificationChannel;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\Alerting\AlertSources;
use App\Services\Alerting\Sources\TraceAlertSource;
use App\Services\Alerting\Templates\AlertProvisioner;
use App\Services\ClickHouse\ClickHouseException;
use App\Services\Logs\LogQuery;
use App\Services\Metrics\MetricQuery;
use App\Services\Traces\TraceQuery;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/*
 * Default alerts (specs/alerting-defaults.md §2–3): the catalogue, the
 * reconciler, its command and schedule, and the team-creation hook. The three
 * detection reads are faked here; their SQL is the query services' own.
 */

/**
 * Fake the three detection reads. `true`/`false` answers, a Throwable is thrown.
 *
 * @param  array{logs?: bool|Throwable, traces?: bool|Throwable, hosts?: bool|Throwable}  $answers
 */
function fakeDetection(array $answers): void
{
    $answer = function (bool|Throwable $value): bool {
        if ($value instanceof Throwable) {
            throw $value;
        }

        return $value;
    };

    test()->mock(LogQuery::class)->allows('hasAnyLogs')->andReturnUsing(fn () => $answer($answers['logs'] ?? false));
    test()->mock(TraceQuery::class)->allows('hasAnyTraces')->andReturnUsing(fn () => $answer($answers['traces'] ?? false));
    test()->mock(MetricQuery::class)->allows('hasHosts')->andReturnUsing(fn () => $answer($answers['hosts'] ?? false));
}

function teamWithProject(): Team
{
    $team = Team::factory()->create();
    Project::factory()->forTeam($team)->create();

    return $team;
}

/**
 * Publish version 2 of a template. Keys contain dots, so the catalogue is
 * replaced whole rather than set by dot path.
 */
function bumpTemplate(string $key, float $threshold, ?string $name = null): void
{
    $templates = config('alerting_templates.templates');
    $templates[$key]['version'] = 2;
    $templates[$key]['rule']['threshold'] = $threshold;
    $templates[$key]['name'] = $name ?? $templates[$key]['name'];

    config()->set('alerting_templates.templates', $templates);
}

const TEMPLATE_KEYS = [
    'logs.error_spike',
    'traces.error_rate',
    'traces.latency_regression',
    'hosts.disk_full',
    'hosts.memory_pressure',
    'hosts.silent',
];

it('ships the six templates of the spec', function () {
    expect(array_keys(config('alerting_templates.templates')))->toBe(TEMPLATE_KEYS);
});

it('defines every template so its source and the rule form accept it', function (string $key) {
    $template = config('alerting_templates.templates')[$key];
    $rule = $template['rule'];

    expect($template['version'])->toBeInt()->toBeGreaterThan(0)
        ->and($template['requires'])->toBeIn(['logs', 'traces', 'hosts'])
        ->and($template['name'])->toBeString()->not->toBe('')
        ->and($template['why'])->toBeString()->toEndWith('.');

    $source = app(AlertSources::class)->for($rule['source']);
    $maxWindow = $rule['source'] === 'traces' ? TraceAlertSource::MAX_WINDOW_SECONDS : SaveAlertRuleRequest::MAX_WINDOW_SECONDS;

    $validator = Validator::make(['name' => $template['name'], ...$rule], [
        'name' => ['required', 'string', 'max:120'],
        'condition' => ['required', Rule::enum(AlertCondition::class)],
        'threshold' => ['required', 'numeric'],
        'window_seconds' => ['required', 'integer', 'min:'.SaveAlertRuleRequest::MIN_WINDOW_SECONDS, 'max:'.$maxWindow],
        'interval_seconds' => ['required', 'integer', Rule::in(SaveAlertRuleRequest::INTERVALS)],
        'for_seconds' => ['required', 'integer', 'min:0', 'max:'.SaveAlertRuleRequest::MAX_HOLD_SECONDS],
        'keep_firing_for_seconds' => ['required', 'integer', 'min:0', 'max:'.SaveAlertRuleRequest::MAX_HOLD_SECONDS],
        'no_data_state' => ['required', Rule::enum(NoDataState::class)],
        'severity' => ['required', 'string'],
        ...$source->rules(),
    ]);

    expect($validator->errors()->all())->toBe([])
        // Every template covers new services and hosts the moment they report.
        ->and($rule['query']['group_by'])->toBeIn(['service.name', 'host.name']);
})->with(TEMPLATE_KEYS);

it('can never be true for hosts.silent, which fires only on no data', function () {
    $rule = config('alerting_templates.templates')['hosts.silent']['rule'];

    expect($rule['condition'])->toBe('<')
        ->and($rule['threshold'])->toBe(0)
        ->and($rule['no_data_state'])->toBe('firing');
});

it('creates the Team email channel once, and never again after it is deleted', function () {
    fakeDetection([]);
    $team = Team::factory()->create();

    $first = app(AlertProvisioner::class)->provision($team);

    $channel = NotificationChannel::query()->where('team_id', $team->id)->sole();

    expect($first->channelCreated)->toBeTrue()
        ->and($channel->name)->toBe('Team email')
        ->and($channel->type)->toBe(NotificationChannelType::Email)
        ->and($channel->config)->toBe(['recipients' => 'members'])
        ->and($channel->issue_events)->toBeTrue()
        ->and($channel->enabled)->toBeTrue()
        ->and($team->fresh()->alerting_provisioned_at)->not->toBeNull();

    $channel->delete();

    $second = app(AlertProvisioner::class)->provision($team->fresh());

    expect($second->channelCreated)->toBeFalse()
        ->and(NotificationChannel::query()->where('team_id', $team->id)->count())->toBe(0);
});

it('creates only the templates whose data is detected, linked to the issue-alert channels', function () {
    fakeDetection(['logs' => true, 'traces' => false, 'hosts' => true]);
    $team = teamWithProject();
    $slack = NotificationChannel::factory()->for($team)->create(['type' => NotificationChannelType::Slack, 'config' => ['url' => 'https://hooks.slack.com/services/T/B/x'], 'issue_events' => true]);
    $quiet = NotificationChannel::factory()->for($team)->create(['issue_events' => false]);

    $result = app(AlertProvisioner::class)->provision($team);

    expect($result->created)->toBe(['logs.error_spike', 'hosts.disk_full', 'hosts.memory_pressure', 'hosts.silent'])
        ->and(AlertRule::query()->where('team_id', $team->id)->pluck('template_key')->sort()->values()->all())
        ->toBe(['hosts.disk_full', 'hosts.memory_pressure', 'hosts.silent', 'logs.error_spike']);

    $teamEmail = NotificationChannel::query()->where('team_id', $team->id)->where('name', 'Team email')->sole();
    $rule = AlertRule::query()->where('team_id', $team->id)->where('template_key', 'logs.error_spike')->sole();

    expect($rule->name)->toBe('Error spike')
        ->and($rule->project_ids)->toBeNull()
        ->and($rule->created_by)->toBeNull()
        ->and($rule->next_evaluation_at)->toBeNull()
        ->and($rule->enabled)->toBeTrue()
        ->and($rule->template_version)->toBe(1)
        ->and($rule->customized_at)->toBeNull()
        ->and($rule->isTemplate())->toBeTrue()
        ->and($rule->query['compare'])->toBe(['offset_seconds' => 604_800])
        ->and($rule->notificationChannels->pluck('id')->sort()->values()->all())->toBe(collect([$teamEmail->id, $slack->id])->sort()->values()->all())
        ->and($rule->notificationChannels->pluck('id'))->not->toContain($quiet->id);
});

it('provisions no templates for a team without projects, but still its channel', function () {
    $team = Team::factory()->create();

    // No mocks: with no project ids the services answer false without a read.
    $result = app(AlertProvisioner::class)->provision($team);

    expect($result->channelCreated)->toBeTrue()
        ->and($result->created)->toBe([])
        ->and(AlertRule::query()->count())->toBe(0);
});

it('never duplicates a rule and reads nothing once everything is installed', function () {
    fakeDetection(['logs' => true, 'traces' => true, 'hosts' => true]);
    $team = teamWithProject();

    app(AlertProvisioner::class)->provision($team);
    $second = app(AlertProvisioner::class)->provision($team->fresh());

    expect(AlertRule::query()->where('team_id', $team->id)->count())->toBe(6)
        ->and(NotificationChannel::query()->where('team_id', $team->id)->count())->toBe(1)
        ->and($second->changed())->toBeFalse();

    app(LogQuery::class)->shouldHaveReceived('hasAnyLogs')->once();
    app(TraceQuery::class)->shouldHaveReceived('hasAnyTraces')->once();
    app(MetricQuery::class)->shouldHaveReceived('hasHosts')->once();
});

it('upgrades an untouched rule on a version bump, leaving its switches alone', function () {
    fakeDetection(['logs' => true]);
    $team = teamWithProject();
    app(AlertProvisioner::class)->provision($team);

    $rule = AlertRule::query()->where('template_key', 'logs.error_spike')->sole();
    $rule->notificationChannels()->detach();
    $rule->forceFill(['muted_until' => now()->addHour()])->save();

    bumpTemplate('logs.error_spike', threshold: 5, name: 'Error surge');

    $result = app(AlertProvisioner::class)->provision($team->fresh());
    $rule->refresh();

    expect($result->upgraded)->toBe(['logs.error_spike'])
        ->and($rule->template_version)->toBe(2)
        ->and($rule->threshold)->toBe(5.0)
        ->and($rule->name)->toBe('Error surge')
        ->and($rule->enabled)->toBeTrue()
        ->and($rule->muted_until)->not->toBeNull()
        ->and($rule->notificationChannels()->count())->toBe(0);
});

it('leaves customized and turned-off rules alone, and never recreates them', function () {
    fakeDetection(['logs' => true, 'traces' => true]);
    $team = teamWithProject();
    app(AlertProvisioner::class)->provision($team);

    $edited = AlertRule::query()->where('template_key', 'logs.error_spike')->sole();
    $edited->forceFill(['threshold' => 10, 'customized_at' => now()])->save();
    $off = AlertRule::query()->where('template_key', 'traces.error_rate')->sole();
    $off->forceFill(['enabled' => false])->save();

    foreach (['logs.error_spike', 'traces.error_rate'] as $key) {
        bumpTemplate($key, threshold: 99);
    }

    $result = app(AlertProvisioner::class)->provision($team->fresh());

    expect($result->upgraded)->toBe([])
        ->and($result->created)->toBe([])
        ->and($edited->fresh()->threshold)->toBe(10.0)
        ->and($edited->fresh()->template_version)->toBe(1)
        ->and($off->fresh()->threshold)->toBe(0.05)
        ->and($off->fresh()->enabled)->toBeFalse()
        ->and(AlertRule::query()->where('team_id', $team->id)->count())->toBe(3);
});

it('skips a kind it cannot detect, installs the rest, and retries next run', function () {
    fakeDetection(['logs' => new ClickHouseException('connection refused'), 'traces' => true]);
    $team = teamWithProject();

    $result = app(AlertProvisioner::class)->provision($team);

    expect($result->undetectable)->toBe(['logs'])
        ->and($result->created)->toBe(['traces.error_rate', 'traces.latency_regression'])
        ->and(AlertRule::query()->where('template_key', 'logs.error_spike')->exists())->toBeFalse();

    fakeDetection(['logs' => true, 'traces' => true]);

    expect(app(AlertProvisioner::class)->provision($team->fresh())->created)->toBe(['logs.error_spike']);
});

it('queues provisioning when a team is created, personal teams included', function () {
    Queue::fake();
    $user = User::factory()->create();

    $team = app(CreateTeam::class)->handle($user, 'Platform');

    Queue::assertPushedOn('alerts', ProvisionTeamAlerts::class, fn (ProvisionTeamAlerts $job): bool => $job->teamId === $team->id);

    $this->post(route('register.store'), [
        'name' => 'New Person',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $personal = User::query()->where('email', 'new@example.com')->sole()->personalTeam();

    expect($personal)->not->toBeNull();
    Queue::assertPushed(ProvisionTeamAlerts::class, fn (ProvisionTeamAlerts $job): bool => $job->teamId === $personal->id);
});

it('gives a newly created team its channel straight away', function () {
    $team = app(CreateTeam::class)->handle(User::factory()->create(), 'Platform');

    expect(NotificationChannel::query()->where('team_id', $team->id)->where('name', 'Team email')->exists())->toBeTrue();
});

it('dispatches one job per team on the alerts queue', function () {
    Queue::fake();
    $teams = Team::factory()->count(3)->create();

    $this->artisan('alerts:provision')->assertSuccessful();

    Queue::assertPushedTimes(ProvisionTeamAlerts::class, 3);
    Queue::assertPushedOn('alerts', ProvisionTeamAlerts::class);

    foreach ($teams as $team) {
        Queue::assertPushed(ProvisionTeamAlerts::class, fn (ProvisionTeamAlerts $job): bool => $job->teamId === $team->id);
    }
});

it('dispatches nothing when alerting or provisioning is off', function (string $flag) {
    Queue::fake();
    Team::factory()->create();
    config()->set($flag, false);

    $this->artisan('alerts:provision')->assertSuccessful();

    Queue::assertNothingPushed();
})->with(['alerting.enabled', 'alerting.provisioning']);

it('does nothing in the job when provisioning is off', function () {
    config()->set('alerting.provisioning', false);
    $team = Team::factory()->create();

    (new ProvisionTeamAlerts($team->id))->handle(app(AlertProvisioner::class));

    expect(NotificationChannel::query()->count())->toBe(0)
        ->and($team->fresh()->alerting_provisioned_at)->toBeNull();
});

it('is unique per team', function () {
    expect((new ProvisionTeamAlerts(42))->uniqueId())->toBe('42');
});

it('reads a rule\'s catalogue entry by its dotted key', function () {
    $template = AlertRule::factory()->make(['template_key' => 'hosts.disk_full']);
    $custom = AlertRule::factory()->make();

    expect($template->isTemplate())->toBeTrue()
        ->and($template->template()['why'])->toBe(config('alerting_templates.templates')['hosts.disk_full']['why'])
        ->and($custom->isTemplate())->toBeFalse()
        ->and($custom->template())->toBeNull();
});
