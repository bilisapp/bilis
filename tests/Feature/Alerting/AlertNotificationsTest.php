<?php

use App\Enums\AlertCondition;
use App\Enums\AlertEventType;
use App\Enums\AlertSeverity;
use App\Enums\NotificationChannelType;
use App\Enums\TeamRole;
use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\NotificationChannel;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Notifications\AlertNotification;
use App\Notifications\Channels\DeliveryFailed;
use App\Notifications\Channels\SlackWebhookChannel;
use App\Notifications\Channels\WebhookChannel;
use App\Notifications\Middleware\FailWithoutRetry;
use App\Services\Alerting\AlertExplorerLink;
use App\Services\Alerting\AlertMessage;
use App\Services\Alerting\AlertNotifier;
use App\Services\Alerting\Webhooks\StandardWebhookSigner;
use App\Services\Alerting\Webhooks\UnsafeWebhookUrl;
use App\Services\Alerting\Webhooks\WebhookHostResolver;
use App\Services\Metrics\MetricFilters;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * Answer every DNS lookup with the given addresses, so no test reaches DNS.
 *
 * @param  list<string>  $addresses
 */
function answerDnsWith(array $addresses): void
{
    app()->instance(WebhookHostResolver::class, new class($addresses) implements WebhookHostResolver
    {
        /** @param  list<string>  $addresses */
        public function __construct(private array $addresses) {}

        public function resolve(string $host): array
        {
            return $this->addresses;
        }
    });
}

/**
 * A firing event for the rule, as `EvaluateAlertRule` records it.
 *
 * @param  array<string, string>  $labels
 */
function firingEvent(AlertRule $rule, array $labels = ['service.name' => 'checkout'], AlertEventType $type = AlertEventType::Firing, ?float $value = 87.0): AlertEvent
{
    $at = CarbonImmutable::parse('2026-11-08 11:59:00', 'UTC');

    return AlertEvent::factory()->forRule($rule)->create([
        'type' => $type,
        'labels' => $labels,
        'value' => $value,
        'occurred_at' => $at,
        'context' => [
            'labels' => $labels,
            'source' => $rule->source,
            'window' => ['from' => $at->subSeconds($rule->window_seconds)->toIso8601ZuluString(), 'to' => $at->toIso8601ZuluString()],
            'no_data' => false,
            ...($type === AlertEventType::RuleError ? ['error' => 'ClickHouse timed out after 10s'] : []),
        ],
    ]);
}

/**
 * Run one job from the alerts queue the way a Horizon worker would.
 */
function workOneAlertJob(): void
{
    test()->artisan('queue:work', ['connection' => 'database', '--queue' => 'alerts', '--once' => true, '--sleep' => 0])->assertSuccessful();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00:10', 'UTC'));
    answerDnsWith(['93.184.215.14']);

    $this->team = Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $this->project = Project::factory()->forTeam($this->team)->create(['slug' => 'shop']);
    $this->rule = AlertRule::factory()->forTeam($this->team)->create([
        'name' => 'Checkout error spike',
        'project_ids' => [$this->project->id],
    ]);
});

describe('routing', function () {
    it('sends one notification per enabled channel of the rule, and none to others', function () {
        Notification::fake();

        $email = NotificationChannel::factory()->for($this->team)->create(['config' => ['addresses' => ['oncall@acme.test']]]);
        $slack = NotificationChannel::factory()->for($this->team)->slack()->create();
        $disabled = NotificationChannel::factory()->for($this->team)->webhook()->disabled()->create();
        $unlinked = NotificationChannel::factory()->for($this->team)->webhook()->create();
        $this->rule->notificationChannels()->attach([$email->id, $slack->id, $disabled->id]);

        app(AlertNotifier::class)->notify(firingEvent($this->rule));

        Notification::assertSentOnDemandTimes(AlertNotification::class, 2);
        Notification::assertSentOnDemand(AlertNotification::class, fn (AlertNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notification->channelId === $email->id
            && $channels === ['mail']
            && $notifiable->routes['mail'] === ['oncall@acme.test']);
        Notification::assertSentOnDemand(AlertNotification::class, fn (AlertNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notification->channelId === $slack->id
            && $channels === [SlackWebhookChannel::class]
            // Only the id travels through the queue; the URL is read at send time.
            && $notifiable->routes[SlackWebhookChannel::class] === $slack->id);

        expect($unlinked->id)->not->toBeIn([$email->id, $slack->id]);
    });

    it('queues on the alerts queue, after commit, with ten tries and a capped exponential backoff', function () {
        $notification = new AlertNotification(1, NotificationChannelType::Webhook, 1);

        expect($notification->queue)->toBe('alerts')
            ->and($notification->afterCommit)->toBeTrue()
            ->and($notification->tries)->toBe(10)
            ->and($notification->backoff())->toBe([30, 60, 120, 240, 480, 960, 1920, 3600, 3600]);
    });

    it('sends issue events to the team\'s channels flagged for them', function () {
        Notification::fake();

        $flagged = NotificationChannel::factory()->for($this->team)->withIssueEvents()->create();
        NotificationChannel::factory()->for($this->team)->create();
        NotificationChannel::factory()->for($this->team)->withIssueEvents()->disabled()->create();
        NotificationChannel::factory()->withIssueEvents()->create(); // another team's

        app(AlertNotifier::class)->notify(AlertEvent::factory()->issueNew()->create(['team_id' => $this->team->id]));

        Notification::assertSentOnDemandTimes(AlertNotification::class, 1);
        Notification::assertSentOnDemand(AlertNotification::class, fn (AlertNotification $notification): bool => $notification->channelId === $flagged->id);
    });

    it('resolves members recipients to the team members\' emails', function () {
        Notification::fake();

        foreach (['ana@acme.test', 'bo@acme.test'] as $address) {
            $this->team->members()->attach(User::factory()->create(['email' => $address]), ['role' => TeamRole::Member->value]);
        }
        User::factory()->create(['email' => 'outsider@else.test']);

        $channel = NotificationChannel::factory()->for($this->team)->create(['config' => ['recipients' => 'members']]);
        $this->rule->notificationChannels()->attach($channel);

        app(AlertNotifier::class)->notify(firingEvent($this->rule));

        Notification::assertSentOnDemand(AlertNotification::class, function (AlertNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool {
            $routes = $notifiable->routes['mail'];
            sort($routes);

            return $routes === ['ana@acme.test', 'bo@acme.test'];
        });
    });

    it('records a failure on an email channel that resolves to no recipients', function () {
        Notification::fake();

        $channel = NotificationChannel::factory()->for($this->team)->create(['config' => ['recipients' => 'members']]);
        $this->rule->notificationChannels()->attach($channel);

        app(AlertNotifier::class)->notify(firingEvent($this->rule));

        Notification::assertNothingSent();
        expect($channel->refresh()->last_failed_at)->not->toBeNull()
            ->and($channel->last_error)->toBe(AlertNotifier::NO_RECIPIENTS);
    });

    it('is a no-op once the event has been pruned or the channel switched off', function () {
        Http::fake();

        $channel = NotificationChannel::factory()->for($this->team)->webhook()->create();
        $event = firingEvent($this->rule);
        $notification = new AlertNotification($channel->id, $channel->type, $event->id);

        $event->delete();
        expect($notification->shouldSend(new AnonymousNotifiable, WebhookChannel::class))->toBeFalse();

        $other = firingEvent($this->rule, ['service.name' => 'payments']);
        $channel->update(['enabled' => false]);
        expect((new AlertNotification($channel->id, $channel->type, $other->id))->shouldSend(new AnonymousNotifiable, WebhookChannel::class))->toBeFalse();
    });
});

describe('email', function () {
    it('titles the mail by event type', function (AlertEventType $type, string $subject) {
        $channel = NotificationChannel::factory()->for($this->team)->create();
        $event = firingEvent($this->rule, type: $type, value: $type === AlertEventType::RuleError ? null : 87.0);

        $mail = (new AlertNotification($channel->id, $channel->type, $event->id))->toMail(new AnonymousNotifiable);

        expect($mail->subject)->toBe($subject);
    })->with([
        'firing' => [AlertEventType::Firing, '[FIRING] Checkout error spike — service.name=checkout'],
        'resolved' => [AlertEventType::Resolved, '[RESOLVED] Checkout error spike — service.name=checkout'],
        'rule error' => [AlertEventType::RuleError, '[RULE ERROR] Checkout error spike — service.name=checkout'],
    ]);

    it('delivers the value, threshold, window, severity and a link into the explorer', function () {
        $channel = NotificationChannel::factory()->for($this->team)->create(['config' => ['addresses' => ['oncall@acme.test']]]);
        $this->rule->notificationChannels()->attach($channel);

        app(AlertNotifier::class)->notify(firingEvent($this->rule));

        $messages = app('mailer')->getSymfonyTransport()->messages();
        expect($messages)->toHaveCount(1);

        $email = $messages[0]->getOriginalMessage();
        $text = html_entity_decode((string) $email->getHtmlBody());

        expect($email->getSubject())->toBe('[FIRING] Checkout error spike — service.name=checkout')
            ->and($email->getTo()[0]->getAddress())->toBe('oncall@acme.test')
            ->and($text)->toContain('The value is 87, above the threshold of 25.')
            ->and($text)->toContain('Threshold: > 25')
            ->and($text)->toContain('Severity: warning')
            ->and($text)->toContain('Window: 2026-11-08 11:54 – 11:59 UTC (5 min)')
            ->and($text)->toContain(route('logs.index', [
                'current_team' => 'acme',
                'project' => 'shop',
                'from' => '2026-11-08T11:54:00Z',
                'to' => '2026-11-08T11:59:00Z',
                'service' => 'checkout',
                'severity' => ['error', 'fatal'],
            ]));
    });

    it('describes the event as it was judged, not as the rule reads after an edit', function () {
        $event = firingEvent($this->rule);
        $event->forceFill(['condition' => AlertCondition::GreaterThan, 'severity' => AlertSeverity::Critical])->save();
        $this->rule->update(['condition' => AlertCondition::LessThan, 'severity' => AlertSeverity::Info, 'threshold' => 500]);

        $message = AlertMessage::fromEvent($event->fresh());

        expect($message->headline)->toBe('The value is 87, above the threshold of 25.')
            ->and($message->facts['Threshold'])->toBe('> 25')
            ->and($message->facts['Severity'])->toBe('critical')
            ->and($message->severity)->toBe(AlertSeverity::Critical)
            ->and($message->payload['condition'])->toBe('>')
            ->and($message->payload['severity'])->toBe('critical');
    });

    it('prints small values with significant digits instead of rounding them to zero', function (float $value, string $text) {
        expect(AlertMessage::number($value))->toBe($text);
    })->with([
        [0.00003, '0.00003'],
        [0.0000312345, '0.0000312'],
        [-0.00005, '-0.00005'],
        [0.0001, '0.0001'],
        [87.123456, '87.1235'],
        [0.0, '0'],
        [-0.0, '0'],
    ]);

    it('links trace and metric rules into their own explorers', function () {
        $channel = NotificationChannel::factory()->for($this->team)->create();

        $trace = AlertRule::factory()->forTeam($this->team)->traces()->create(['project_ids' => [$this->project->id]]);
        // An all-projects rule (null) links without a project filter.
        $metric = AlertRule::factory()->forTeam($this->team)->metrics()->create(['project_ids' => null]);

        $traceUrl = (new AlertNotification($channel->id, $channel->type, firingEvent($trace)->id))->message()->url;
        $metricUrl = (new AlertNotification($channel->id, $channel->type, firingEvent($metric, ['host.name' => 'web-1'])->id))->message()->url;

        expect($traceUrl)->toBe(route('traces.index', [
            'current_team' => 'acme', 'project' => 'shop', 'from' => '2026-11-08T11:54:00Z', 'to' => '2026-11-08T11:59:00Z', 'service' => 'checkout',
        ]))->and($metricUrl)->toBe(route('metrics.index', [
            'current_team' => 'acme', 'from' => '2026-11-08T11:54:00Z', 'to' => '2026-11-08T11:59:00Z',
            'metric' => 'system.cpu.utilization', 'where' => ['host.name' => 'web-1'], 'group_by' => 'host.name',
        ]));
    });

    it('carries a metric rule\'s min/max into the explorer and never more filters than it accepts', function () {
        $rule = AlertRule::factory()->forTeam($this->team)->metrics()->create([
            'project_ids' => null,
            'query' => [
                'metric' => 'system.filesystem.utilization',
                'aggregation' => 'max',
                'group_by' => 'host.name',
                'filters' => ['a' => '1', 'b' => '2', 'c' => '3', 'd' => '4', 'e' => '5'],
            ],
        ]);
        $from = CarbonImmutable::parse('2026-11-08T11:54:00Z');
        $to = CarbonImmutable::parse('2026-11-08T11:59:00Z');

        $url = AlertExplorerLink::forRule($rule, $this->team, ['host.name' => 'db-1'], $from, $to);
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $params);

        expect($params['agg'])->toBe('max')
            ->and($params['where'])->toHaveCount(MetricFilters::MAX_WHERE)
            ->and($params['where'])->toHaveKey('host.name', 'db-1');

        $rule->update(['query' => ['metric' => 'system.cpu.utilization', 'aggregation' => 'avg']]);
        parse_str((string) parse_url((string) AlertExplorerLink::forRule($rule, $this->team, [], $from, $to), PHP_URL_QUERY), $params);

        expect($params)->not->toHaveKey('agg')->not->toHaveKey('where');
    });
});

describe('slack', function () {
    it('posts mrkdwn text with the link to the pinned hooks.slack.com address', function () {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);

        $channel = NotificationChannel::factory()->for($this->team)->slack()->create();
        $this->rule->notificationChannels()->attach($channel);

        app(AlertNotifier::class)->notify(firingEvent($this->rule));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($channel): bool {
            $text = $request->data()['text'];

            return $request->url() === $channel->config['url']
                && $request->method() === 'POST'
                && str_starts_with($text, "*[FIRING] Checkout error spike — service.name=checkout*\nThe value is 87, above the threshold of 25.")
                && str_contains($text, '• Severity: warning')
                && str_contains($text, '|Open in Bilis>');
        });
    });

    it('escapes Slack control characters in rule text', function () {
        Http::fake();

        $channel = NotificationChannel::factory()->for($this->team)->slack()->create();
        $this->rule->update(['name' => '<!channel> & co']);
        $this->rule->notificationChannels()->attach($channel);

        app(AlertNotifier::class)->notify(firingEvent($this->rule));

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->data()['text'], '*[FIRING] &lt;!channel&gt; &amp; co'));
    });

    it('refuses a Slack channel whose URL is not on hooks.slack.com', function () {
        Http::fake();

        $channel = NotificationChannel::factory()->for($this->team)->slack()->create(['config' => ['url' => 'https://example.com/services/T0/B0/x']]);

        expect(fn () => (new SlackWebhookChannel)->send(new AnonymousNotifiable, new AlertNotification($channel->id, $channel->type, firingEvent($this->rule)->id)))
            ->toThrow(UnsafeWebhookUrl::class, 'The URL must be on hooks.slack.com.');

        Http::assertNothingSent();
    });

    it('throws on a non-2xx answer so the queue retries', function () {
        Http::fake(['*' => Http::response('no_service', 404)]);

        $channel = NotificationChannel::factory()->for($this->team)->slack()->create();

        expect(fn () => (new SlackWebhookChannel)->send(new AnonymousNotifiable, new AlertNotification($channel->id, $channel->type, firingEvent($this->rule)->id)))
            ->toThrow(DeliveryFailed::class, 'The endpoint answered HTTP 404.');
    });
});

describe('webhook', function () {
    it('posts a signed Standard Webhooks body with a message id that is stable across retries', function () {
        Http::fake(['*' => Http::response(null, 204)]);

        $channel = NotificationChannel::factory()->for($this->team)->webhook()->create();
        $event = firingEvent($this->rule);
        $notification = new AlertNotification($channel->id, $channel->type, $event->id);

        (new WebhookChannel)->send(new AnonymousNotifiable, $notification);
        $this->travel(90)->seconds();
        (new WebhookChannel)->send(new AnonymousNotifiable, $notification);

        $sent = Http::recorded()->map(fn (array $pair): Request => $pair[0]);
        expect($sent)->toHaveCount(2);

        $signer = new StandardWebhookSigner($channel->config['secret']);

        foreach ($sent as $request) {
            expect($request->url())->toBe($channel->config['url'])
                ->and($request->header('Content-Type')[0])->toBe('application/json')
                ->and($request->header('webhook-id')[0])->toBe('msg_'.$event->id)
                ->and($signer->verify($request->body(), $request->headers(), (int) $request->header('webhook-timestamp')[0]))->toBeTrue();
        }

        expect(json_decode($sent[0]->body(), true))->toBe([
            'type' => 'alert.firing',
            'timestamp' => '2026-11-08T11:59:00Z',
            'data' => [
                'event_id' => $event->id,
                'rule' => ['id' => $this->rule->id, 'name' => 'Checkout error spike', 'severity' => 'warning', 'template_key' => null],
                'labels' => ['service.name' => 'checkout'],
                'value' => 87.0,
                'threshold' => 25.0,
                'condition' => '>',
                'state' => 'firing',
                'severity' => 'warning',
                'window' => ['from' => '2026-11-08T11:54:00Z', 'to' => '2026-11-08T11:59:00Z'],
                'error' => null,
                'issue' => null,
                'links' => [
                    'explorer' => route('logs.index', [
                        'current_team' => 'acme', 'project' => 'shop', 'from' => '2026-11-08T11:54:00Z', 'to' => '2026-11-08T11:59:00Z',
                        'service' => 'checkout', 'severity' => ['error', 'fatal'],
                    ]),
                    'rule' => route('alerts.edit', ['current_team' => 'acme', 'alertRule' => $this->rule->id]),
                ],
            ],
        ]);
    });

    it('throws on a non-2xx answer so the queue retries', function () {
        Http::fake(['*' => Http::response('down', 503)]);

        $channel = NotificationChannel::factory()->for($this->team)->webhook()->create();

        expect(fn () => (new WebhookChannel)->send(new AnonymousNotifiable, new AlertNotification($channel->id, $channel->type, firingEvent($this->rule)->id)))
            ->toThrow(DeliveryFailed::class, 'The endpoint answered HTTP 503.');
    });
});

describe('failure recording', function () {
    beforeEach(function () {
        config(['queue.default' => 'database']);
    });

    it('fails an unsafe webhook URL at once, records it on the channel and does not retry', function () {
        Http::fake();
        answerDnsWith(['10.0.0.8']);

        $channel = NotificationChannel::factory()->for($this->team)->webhook()->create();
        $this->rule->notificationChannels()->attach($channel);

        app(AlertNotifier::class)->notify(firingEvent($this->rule));
        workOneAlertJob();

        Http::assertNothingSent();
        expect(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(1);

        $channel->refresh();
        expect($channel->last_failed_at)->not->toBeNull()
            ->and($channel->last_error)->toBe('The host '.parse_url($channel->config['url'], PHP_URL_HOST).' resolves to a private or reserved address, which webhooks cannot be sent to.')
            ->and($channel->last_error)->not->toContain($channel->config['secret']);
    });

    it('releases a transient failure for a retry without recording it yet', function () {
        Http::fake(['*' => Http::response('down', 503)]);

        $channel = NotificationChannel::factory()->for($this->team)->webhook()->create();
        $this->rule->notificationChannels()->attach($channel);

        app(AlertNotifier::class)->notify(firingEvent($this->rule));
        workOneAlertJob();

        expect(DB::table('jobs')->count())->toBe(1)
            ->and(DB::table('jobs')->value('attempts'))->toBe(1)
            ->and(DB::table('failed_jobs')->count())->toBe(0)
            ->and($channel->refresh()->last_failed_at)->toBeNull();
    });

    it('records the last failure once the retries run out, never the URL', function () {
        $channel = NotificationChannel::factory()->for($this->team)->slack()->create();

        (new AlertNotification($channel->id, $channel->type, 1))->failed(DeliveryFailed::status(410));
        expect($channel->refresh()->last_error)->toBe('The endpoint answered HTTP 410.');

        (new AlertNotification($channel->id, $channel->type, 1))->failed(new RuntimeException("cURL error 7: {$channel->config['url']}"));
        expect($channel->refresh()->last_error)->toBe('The notification could not be delivered.');
    });

    it('clears the recorded failure after a successful send', function () {
        Http::fake();

        $channel = NotificationChannel::factory()->for($this->team)->slack()->failing()->create();
        $this->rule->notificationChannels()->attach($channel);

        app(AlertNotifier::class)->notify(firingEvent($this->rule));
        workOneAlertJob();

        expect($channel->refresh()->last_failed_at)->toBeNull()
            ->and($channel->last_error)->toBeNull();
    });

    it('keeps the recorded failure when the send was skipped', function () {
        Http::fake();

        $channel = NotificationChannel::factory()->for($this->team)->slack()->failing()->create();
        $this->rule->notificationChannels()->attach($channel);

        app(AlertNotifier::class)->notify(firingEvent($this->rule));
        $channel->update(['enabled' => false]);
        workOneAlertJob();

        Http::assertNothingSent();
        expect($channel->refresh()->last_failed_at)->not->toBeNull()
            ->and($channel->last_error)->not->toBeNull();
    });

    it('treats unsafe URLs and broken channel config as permanent, everything else as transient', function () {
        expect(FailWithoutRetry::isPermanent(new UnsafeWebhookUrl('no')))->toBeTrue()
            ->and(FailWithoutRetry::isPermanent(DeliveryFailed::misconfigured('no')))->toBeTrue()
            ->and(FailWithoutRetry::isPermanent(DeliveryFailed::status(500)))->toBeFalse()
            ->and(FailWithoutRetry::isPermanent(new RuntimeException('no')))->toBeFalse();
    });
});

describe('send test', function () {
    it('sends a labelled test message synchronously', function () {
        config(['queue.default' => 'database']);
        Http::fake();

        $channel = NotificationChannel::factory()->for($this->team)->webhook()->failing()->create();

        app(AlertNotifier::class)->sendTest($channel);

        expect(DB::table('jobs')->count())->toBe(0);
        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['type'] === 'alert.test'
                && $body['data']['state'] === 'test'
                && str_starts_with($request->header('webhook-id')[0], 'msg_test_');
        });
        expect($channel->refresh()->last_failed_at)->toBeNull();
    });

    it('surfaces the failure to the caller', function () {
        Http::fake(['*' => Http::response('gone', 410)]);

        $channel = NotificationChannel::factory()->for($this->team)->slack()->create();

        expect(fn () => app(AlertNotifier::class)->sendTest($channel))
            ->toThrow(DeliveryFailed::class, 'The endpoint answered HTTP 410.');
    });

    it('surfaces an unsafe URL to the caller', function () {
        answerDnsWith(['127.0.0.1']);
        Http::fake();

        $channel = NotificationChannel::factory()->for($this->team)->webhook()->create();

        expect(fn () => app(AlertNotifier::class)->sendTest($channel))->toThrow(UnsafeWebhookUrl::class);
        Http::assertNothingSent();
    });

    it('mails the test message to the listed addresses', function () {
        $channel = NotificationChannel::factory()->for($this->team)->create(['config' => ['addresses' => ['oncall@acme.test']]]);

        app(AlertNotifier::class)->sendTest($channel);

        $messages = app('mailer')->getSymfonyTransport()->messages();
        expect($messages)->toHaveCount(1)
            ->and($messages[0]->getOriginalMessage()->getSubject())->toBe('[TEST] Bilis notification test');
    });
});
