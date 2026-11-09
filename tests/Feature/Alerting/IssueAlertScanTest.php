<?php

use App\Enums\AlertEventType;
use App\Jobs\ScanIssueAlerts;
use App\Models\AlertEvent;
use App\Models\Issue;
use App\Models\NotificationChannel;
use App\Models\Project;
use App\Models\Team;
use App\Notifications\AlertNotification;
use App\Notifications\Channels\SlackWebhookChannel;
use App\Notifications\Channels\WebhookChannel;
use App\Services\Alerting\AlertMessage;
use App\Services\Alerting\IssueAlertScan;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * One `newIssueCandidates()` / `regressionCandidates()` row as ClickHouse
 * answers it (JSONEachRow).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function issueCandidateRow(Project $project, string $fingerprint, array $overrides = []): array
{
    return array_merge([
        'ProjectId' => (string) $project->id,
        'IssueFingerprint' => $fingerprint,
        'IssueFirstSeen' => CarbonImmutable::now()->subMinute()->getTimestampMs(),
        'IssueLastIngested' => CarbonImmutable::now()->subMinute()->getTimestamp(),
        'IssueEvents' => 3,
        'IssueType' => 'TypeError',
        'IssueMessage' => "Cannot read properties of undefined (reading 'total')",
        'IssueService' => 'checkout',
    ], $overrides);
}

/**
 * Answer every ClickHouse select with these rows. Calling it again replaces
 * the answer (a second `Http::fake()` would only queue behind the first).
 *
 * @param  list<array<string, mixed>>  $rows
 */
function fakeIssueCandidates(array $rows): void
{
    $first = ! app()->bound('test.issue-rows');
    app()->instance('test.issue-rows', $rows);

    if ($first) {
        Http::fake(['127.0.0.1:8123/*' => fn () => Http::response(implode('', array_map(
            fn (array $row): string => json_encode($row)."\n",
            app('test.issue-rows'),
        )))]);
    }
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00:10', 'UTC'));
    Notification::fake();

    $this->team = Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $this->project = Project::factory()->forTeam($this->team)->create(['name' => 'Shop', 'slug' => 'shop']);
    $this->channel = NotificationChannel::factory()->for($this->team)->withIssueEvents()->create();
});

describe('scan', function () {
    it('records and notifies a new issue once, however often the window overlaps', function () {
        $fingerprint = str_repeat('a', 64);
        fakeIssueCandidates([issueCandidateRow($this->project, $fingerprint)]);

        expect(app(IssueAlertScan::class)->scan())->toBe(1);

        $event = AlertEvent::query()->sole();
        expect($event->type)->toBe(AlertEventType::IssueNew)
            ->and($event->alert_rule_id)->toBeNull()
            ->and($event->team_id)->toBe($this->team->id)
            ->and($event->dedupe_key)->toBe("issue:{$this->project->id}:{$fingerprint}:new")
            ->and($event->context)->toMatchArray([
                'project_slug' => 'shop',
                'project_name' => 'Shop',
                'fingerprint' => $fingerprint,
                'exception_type' => 'TypeError',
                'service' => 'checkout',
                'events' => 3,
            ]);

        Notification::assertSentOnDemandTimes(AlertNotification::class, 1);

        // The next tick sees the same issue inside its lookback: nothing new.
        $this->travel(1)->minute();
        expect(app(IssueAlertScan::class)->scan())->toBe(0)
            ->and(AlertEvent::query()->count())->toBe(1);
        Notification::assertSentOnDemandTimes(AlertNotification::class, 1);
    });

    it('reads the current and previous hour, for the team\'s own projects only, bound as parameters', function () {
        Project::factory()->create(); // another team's
        fakeIssueCandidates([]);

        app(IssueAlertScan::class)->scan();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['param_projectIds'] === "['{$this->project->id}']"
                && $query['param_hourFrom'] === '2026-11-08 11:00:00'
                && $query['param_firstSeenSince'] === (string) CarbonImmutable::parse('2026-11-08 11:57:10', 'UTC')->getTimestampMs()
                && str_contains($request->body(), 'FROM issue_hourly')
                && str_contains($request->body(), 'GROUP BY ProjectId, IssueFingerprint');
        });
    });

    it('looks a resolved issue up by key on its arrival time, never by event hour', function () {
        $issue = Issue::factory()->resolved(now()->subMinutes(10))->create(['project_id' => $this->project->id]);
        Issue::factory()->ignored()->create(['project_id' => $this->project->id]);

        Http::fake(function (Request $request) use ($issue) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            // A buffered SDK's two-day-old event that arrived a minute ago: the hour nomination never sees it.
            return str_contains($request->body(), 'FROM issue_hourly')
                ? Http::response('')
                : Http::response(json_encode(issueCandidateRow($this->project, $issue->fingerprint, ['IssueFirstSeen' => now()->subDays(2)->getTimestampMs()]))."\n");
        });

        expect(app(IssueAlertScan::class)->scan())->toBe(1)
            ->and(AlertEvent::query()->sole()->type)->toBe(AlertEventType::IssueRegressed);

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) use ($issue): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ! str_contains($request->body(), 'issue_hourly')
                && $query['param_fingerprints'] === "['{$issue->fingerprint}']"
                && $query['param_ingestedSince'] === (string) CarbonImmutable::parse('2026-11-08 11:57:10', 'UTC')->getTimestamp();
        });
    });

    it('gives new issues their own cap, so a crowd of regressions cannot push them out', function () {
        $resolved = Issue::factory()->count(3)->resolved(now()->subMinutes(10))->create(['project_id' => $this->project->id]);
        $new = str_repeat('e', 64);

        Http::fake(fn (Request $request) => Http::response(str_contains($request->body(), 'FROM issue_hourly')
            ? json_encode(issueCandidateRow($this->project, $new))."\n"
            : implode('', $resolved->map(fn (Issue $issue): string => json_encode(issueCandidateRow($this->project, $issue->fingerprint, ['IssueFirstSeen' => now()->subDays(3)->getTimestampMs()]))."\n")->all())));

        expect(app(IssueAlertScan::class)->scan())->toBe(4)
            ->and(AlertEvent::query()->where('type', AlertEventType::IssueNew)->sole()->dedupe_key)->toBe("issue:{$this->project->id}:{$new}:new")
            ->and(AlertEvent::query()->where('type', AlertEventType::IssueRegressed)->count())->toBe(3);
    });

    it('reads the people\'s decisions in a fixed number of queries, however many projects are involved', function () {
        $projects = Project::factory()->count(4)->forTeam($this->team)->create()->push($this->project);
        $rows = $projects->map(fn (Project $project): array => issueCandidateRow($project, hash('sha256', (string) $project->id)))->all();
        Issue::factory()->ignored()->create(['project_id' => $projects->first()->id, 'fingerprint' => hash('sha256', (string) $projects->first()->id)]);
        fakeIssueCandidates($rows);

        DB::enableQueryLog();
        expect(app(IssueAlertScan::class)->scan())->toBe(4);

        $issueReads = collect(DB::getQueryLog())->filter(fn (array $entry): bool => str_starts_with($entry['query'], 'select') && str_contains($entry['query'], '"issues"'));
        expect($issueReads)->toHaveCount(2);
    });

    it('skips an issue someone has ignored, and one first seen before the lookback', function () {
        $ignored = Issue::factory()->ignored()->create(['project_id' => $this->project->id]);
        fakeIssueCandidates([
            issueCandidateRow($this->project, $ignored->fingerprint),
            issueCandidateRow($this->project, str_repeat('b', 64), ['IssueFirstSeen' => now()->subHour()->getTimestampMs()]),
        ]);

        expect(app(IssueAlertScan::class)->scan())->toBe(0);
        Notification::assertNothingSent();
    });

    it('records a regression, and again after the issue is resolved and comes back once more', function () {
        $issue = Issue::factory()->resolved(now()->subMinutes(10))->create(['project_id' => $this->project->id]);
        $old = ['IssueFirstSeen' => now()->subDays(3)->getTimestampMs()];

        fakeIssueCandidates([issueCandidateRow($this->project, $issue->fingerprint, $old)]);
        expect(app(IssueAlertScan::class)->scan())->toBe(1);
        expect(app(IssueAlertScan::class)->scan())->toBe(0);

        $first = AlertEvent::query()->sole();
        expect($first->type)->toBe(AlertEventType::IssueRegressed)
            ->and($first->dedupe_key)->toBe("issue:{$this->project->id}:{$issue->fingerprint}:regressed:".$issue->resolved_at->getTimestamp());

        // Resolved again; a later event regresses it again, under a new key.
        $issue->update(['resolved_at' => now()]);
        $this->travel(2)->minutes();
        fakeIssueCandidates([issueCandidateRow($this->project, $issue->fingerprint, [...$old, 'IssueLastIngested' => now()->getTimestamp()])]);

        expect(app(IssueAlertScan::class)->scan())->toBe(1)
            ->and(AlertEvent::query()->where('type', AlertEventType::IssueRegressed)->count())->toBe(2);
    });

    it('does not call a resolved issue regressed when nothing arrived after the resolution', function () {
        $issue = Issue::factory()->resolved(now())->create(['project_id' => $this->project->id]);
        fakeIssueCandidates([issueCandidateRow($this->project, $issue->fingerprint, ['IssueFirstSeen' => now()->subDay()->getTimestampMs()])]);

        expect(app(IssueAlertScan::class)->scan())->toBe(0);
    });

    it('caps new-issue events per team per scan', function () {
        fakeIssueCandidates(array_map(
            fn (int $index): array => issueCandidateRow($this->project, hash('sha256', (string) $index)),
            range(1, IssueAlertScan::MAX_NEW_PER_TEAM + 5),
        ));

        expect(app(IssueAlertScan::class)->scan())->toBe(IssueAlertScan::MAX_NEW_PER_TEAM);
    });

    it('never reads ClickHouse for a team without a channel that takes issue events', function () {
        $this->channel->update(['issue_events' => false]);
        NotificationChannel::factory()->for($this->team)->withIssueEvents()->disabled()->create();
        Http::fake();

        expect(app(IssueAlertScan::class)->subscribedTeamIds())->toBe([])
            ->and(app(IssueAlertScan::class)->scan())->toBe(0);

        Http::assertNothingSent();
    });

    it('reports a storage failure, skips that team and carries on with the next', function () {
        $other = Team::factory()->create();
        $otherProject = Project::factory()->forTeam($other)->create();
        NotificationChannel::factory()->for($other)->withIssueEvents()->create();

        Http::fake(function (Request $request) use ($otherProject) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($query['param_projectIds'], "'{$otherProject->id}'")
                ? Http::response(json_encode(issueCandidateRow($otherProject, str_repeat('c', 64)))."\n")
                : Http::response('Code: 60. DB::Exception: Table bilis.issue_summary does not exist. (UNKNOWN_TABLE)', 404);
        });

        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (Throwable $exception) use (&$reported) {
            $reported[] = $exception;
        });

        expect(app(IssueAlertScan::class)->scan())->toBe(1)
            ->and($reported)->toHaveCount(1)
            ->and(AlertEvent::query()->sole()->team_id)->toBe($other->id);
    });
});

describe('dispatch', function () {
    it('queues one unique scan per subscribed team per minute on the alerts queue', function () {
        Queue::fake();
        $other = Team::factory()->create();
        NotificationChannel::factory()->for($other)->withIssueEvents()->create();
        Team::factory()->create(); // no channel: no scan

        $this->artisan('alerts:evaluate')->assertSuccessful();

        $minute = CarbonImmutable::parse('2026-11-08 12:00:00', 'UTC')->getTimestamp();
        Queue::assertPushedOn('alerts', ScanIssueAlerts::class);
        Queue::assertPushedTimes(ScanIssueAlerts::class, 2);
        foreach ([$this->team->id, $other->id] as $teamId) {
            Queue::assertPushed(ScanIssueAlerts::class, fn (ScanIssueAlerts $job): bool => $job->uniqueId() === "{$teamId}:{$minute}"
                && $job->teamId === $teamId
                && $job->tries === 1);
        }
    });

    it('scans only its own team, and nothing once the team has stopped taking issue events', function () {
        $other = Team::factory()->create();
        Project::factory()->forTeam($other)->create();
        NotificationChannel::factory()->for($other)->withIssueEvents()->create();
        fakeIssueCandidates([issueCandidateRow($this->project, str_repeat('f', 64))]);

        (new ScanIssueAlerts($this->team->id, 0))->handle(app(IssueAlertScan::class));
        expect(AlertEvent::query()->sole()->team_id)->toBe($this->team->id);
        Http::assertSentCount(1);

        $this->channel->update(['enabled' => false]);
        (new ScanIssueAlerts($this->team->id, 60))->handle(app(IssueAlertScan::class));
        Http::assertSentCount(1);
    });

    it('queues nothing when no team takes issue events', function () {
        Queue::fake();
        $this->channel->update(['enabled' => false]);

        $this->artisan('alerts:evaluate')->assertSuccessful();

        Queue::assertNotPushed(ScanIssueAlerts::class);
    });
});

describe('message', function () {
    beforeEach(function () {
        $this->fingerprint = str_repeat('d', 64);
        fakeIssueCandidates([issueCandidateRow($this->project, $this->fingerprint)]);
        app(IssueAlertScan::class)->scan();
        $this->event = AlertEvent::query()->sole();
    });

    it('names the issue and its service in the title, and links the issue page', function () {
        $message = AlertMessage::fromEvent($this->event);

        expect($message->title)->toBe("[NEW ISSUE] TypeError: Cannot read properties of undefined (reading 'total') — checkout")
            ->and($message->headline)->toBe('A new issue was seen in Shop — 3 events so far.')
            ->and($message->url)->toBe(route('issues.show', ['current_team' => 'acme', 'project' => 'shop', 'fingerprint' => $this->fingerprint]))
            ->and($message->facts)->toMatchArray(['Project' => 'Shop', 'Service' => 'checkout', 'Events' => '3']);
    });

    it('renders in mail, Slack and the webhook', function () {
        $notification = new AlertNotification($this->channel->id, $this->channel->type, $this->event->id);
        $mail = $notification->toMail(new AnonymousNotifiable);

        expect($mail->subject)->toStartWith('[NEW ISSUE] TypeError:')
            ->and($mail->actionUrl)->toContain('/issues/shop/'.$this->fingerprint);

        $message = AlertMessage::fromEvent($this->event);
        expect(SlackWebhookChannel::text($message))
            ->toStartWith('*[NEW ISSUE] TypeError:')
            ->toContain('• Service: checkout');

        $body = json_decode(WebhookChannel::body($message), true);
        expect($body['type'])->toBe('alert.issue_new')
            ->and($body['data']['rule'])->toBeNull()
            ->and($body['data']['issue'])->toMatchArray([
                'project' => 'shop',
                'fingerprint' => $this->fingerprint,
                'type' => 'TypeError',
                'service' => 'checkout',
                'events' => 3,
            ])
            ->and($body['data']['links']['explorer'])->toContain('/issues/shop/'.$this->fingerprint);
    });

    it('says when a regressed issue had been resolved', function () {
        $issue = Issue::factory()->resolved(CarbonImmutable::parse('2026-11-08 11:50:00', 'UTC'))->create(['project_id' => $this->project->id]);
        fakeIssueCandidates([issueCandidateRow($this->project, $issue->fingerprint, ['IssueFirstSeen' => now()->subDay()->getTimestampMs()])]);
        app(IssueAlertScan::class)->scan();

        $message = AlertMessage::fromEvent(AlertEvent::query()->where('type', AlertEventType::IssueRegressed)->sole());

        expect($message->title)->toStartWith('[REGRESSED ISSUE] TypeError:')
            ->and($message->headline)->toBe('A resolved issue was seen again in Shop, after it was resolved on 2026-11-08 11:50 UTC.')
            ->and($message->facts['Resolved'])->toBe('2026-11-08 11:50:00 UTC');
    });
});
