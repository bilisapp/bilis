<?php

use App\Enums\IssueStatus;
use App\Enums\TeamRole;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\Issues\IssueBadge;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['clickhouse.host' => '127.0.0.1', 'clickhouse.port' => 8123]);
    Carbon::setTestNow('2026-11-07 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

const ISSUE_FP_PAYMENT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const ISSUE_FP_LEDGER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

/**
 * A team with one owner and one project.
 *
 * @return array{0: User, 1: Team, 2: Project}
 */
function issuesTeam(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $project = Project::factory()->forTeam($team)->create(['name' => 'Checkout', 'slug' => 'checkout']);

    return [$user, $team, $project];
}

/**
 * @param  list<array<string, mixed>>  $rows
 */
function issueRows(array $rows): string
{
    return implode('', array_map(fn (array $row): string => json_encode($row)."\n", $rows));
}

function ms(string $time): int
{
    return Carbon::parse($time, 'UTC')->getTimestampMs();
}

/**
 * Answer each issue statement by its shape: two issues in the project, the
 * payment one seen last at 11:50 and the ledger one at 09:00.
 */
function fakeIssuesClickHouse(string $projectId): void
{
    Http::fake(function (Request $request) use ($projectId) {
        $body = $request->body();

        return Http::response(match (true) {
            str_contains($body, 'AS Found') => issueRows([['Found' => 1]]),
            str_contains($body, 'AS WindowSeries') => issueRows([
                ['ProjectId' => $projectId, 'IssueFingerprint' => ISSUE_FP_PAYMENT, 'WindowEvents' => '40', 'WindowUsers' => '12', 'WindowSeries' => [[Carbon::parse('2026-11-07 11:00')->getTimestamp(), '40']]],
                ['ProjectId' => $projectId, 'IssueFingerprint' => ISSUE_FP_LEDGER, 'WindowEvents' => '3', 'WindowUsers' => '0', 'WindowSeries' => [[Carbon::parse('2026-11-07 09:00')->getTimestamp(), '3']]],
            ]),
            str_contains($body, 'AS IssueFirstSeen') => issueRows([
                [
                    'ProjectId' => $projectId, 'IssueFingerprint' => ISSUE_FP_PAYMENT,
                    'IssueFirstSeen' => ms('2026-10-01 08:00'), 'IssueLastSeen' => ms('2026-11-07 11:50'), 'IssueLastIngested' => Carbon::parse('2026-11-07 11:50:02')->getTimestamp(),
                    'IssueEvents' => '900', 'IssueUsers' => '120', 'IssueServices' => ['checkout'], 'IssueEnvironments' => ['production'],
                    'IssueType' => 'App\\Exceptions\\PaymentFailed', 'IssueMessage' => 'Card was declined', 'IssueOrigin' => 'app/Payments/Charge.php:41 charge()',
                    'IssueService' => 'checkout', 'IssueLastRelease' => '1.4.0', 'IssueFirstRelease' => '1.2.0',
                ],
                [
                    'ProjectId' => $projectId, 'IssueFingerprint' => ISSUE_FP_LEDGER,
                    'IssueFirstSeen' => ms('2026-11-07 09:00'), 'IssueLastSeen' => ms('2026-11-07 09:10'), 'IssueLastIngested' => Carbon::parse('2026-11-07 09:10:01')->getTimestamp(),
                    'IssueEvents' => '3', 'IssueUsers' => '0', 'IssueServices' => ['billing'], 'IssueEnvironments' => ['staging'],
                    'IssueType' => 'RuntimeException', 'IssueMessage' => 'Ledger locked', 'IssueOrigin' => '',
                    'IssueService' => 'billing', 'IssueLastRelease' => '', 'IssueFirstRelease' => '',
                ],
            ]),
            str_contains($body, 'AS BucketEvents') => issueRows([
                ['Bucket' => Carbon::parse('2026-11-07 11:00')->getTimestamp(), 'BucketEvents' => '40', 'BucketUsers' => '12'],
            ]),
            str_contains($body, 'AS HasOlder') => issueRows([['HasOlder' => 1, 'HasNewer' => 0]]),
            str_contains($body, 'AS EventMessage') => issueRows([[
                'Timestamp' => '2026-11-07 11:50:00.000000000', 'ServiceName' => 'checkout', 'TraceId' => '', 'EventId' => 'e1',
                'EventRelease' => '1.4.0', 'EventEnvironment' => 'production', 'EventUser' => 'alice', 'EventMessage' => 'Card was declined',
            ]]),
            str_contains($body, 'AS TagTotal') => issueRows([
                ['TagKey' => 'service.version', 'TagValue' => '1.4.0', 'TagCount' => '30', 'TagTotal' => '40', 'Sampled' => '40'],
                ['TagKey' => 'service.version', 'TagValue' => '1.3.0', 'TagCount' => '10', 'TagTotal' => '40', 'Sampled' => '40'],
            ]),
            str_contains($body, 'FROM otel_logs') => issueRows([[
                'ProjectId' => $projectId, 'Timestamp' => '2026-11-07 11:50:00.000000000', 'TraceId' => '', 'SpanId' => '',
                'SeverityText' => 'ERROR', 'SeverityNumber' => 17, 'ServiceName' => 'checkout',
                'Body' => 'App\\Exceptions\\PaymentFailed: Card was declined', 'ScopeName' => '', 'ScopeVersion' => '',
                'ResourceAttributes' => [], 'LogAttributes' => [
                    'exception.type' => 'App\\Exceptions\\PaymentFailed',
                    'exception.message' => 'Card was declined',
                    'exception.stacktrace' => '#0 app/Payments/Charge.php:41 charge()',
                    'exception.frames' => '[{"filename":"app/Payments/Charge.php","lineno":41,"in_app":true}]',
                ],
                'IssueEventKey' => '1234567890123',
            ]]),
            default => '',
        });
    });
}

test('guests are sent to log in', function () {
    [, $team] = issuesTeam();

    $this->get(route('issues.index', ['current_team' => $team->slug]))->assertRedirect(route('login'));
});

test('the list merges ClickHouse counts with each issue\'s status', function () {
    [$user, $team, $project] = issuesTeam();
    fakeIssuesClickHouse((string) $project->id);

    $this->actingAs($user)
        ->get(route('issues.index', ['current_team' => $team->slug, 'period' => '24h']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('issues/Index')
            ->where('hasIssues', true)
            ->where('filters.period', '24h')
            ->where('filters.status', 'unresolved')
            ->missing('result')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('result.total', 2)
                // Sorted by last seen: the payment issue first.
                ->where('result.issues.0.fingerprint', ISSUE_FP_PAYMENT)
                ->where('result.issues.0.type', 'App\\Exceptions\\PaymentFailed')
                ->where('result.issues.0.projectSlug', 'checkout')
                ->where('result.issues.0.windowEvents', 40)
                ->where('result.issues.0.users', 120)
                ->where('result.issues.0.status', 'unresolved')
                ->where('result.issues.0.isNew', false)
                ->where('result.issues.1.isNew', true)
                ->where('result.services', ['billing', 'checkout'])
                ->where('result.environments', ['production', 'staging'])
                // 24h at exactly 12:00: one point per hour, twenty-four whole hours.
                ->has('result.issues.0.series', 24)
                ->etc()
            )
        );
});

test('the status filter reads regressed as unresolved and hides ignored issues', function () {
    [$user, $team, $project] = issuesTeam();
    fakeIssuesClickHouse((string) $project->id);

    // Resolved before its last event at 11:50: regressed.
    Issue::factory()->for($project)->resolved(Carbon::parse('2026-11-07 10:00'))->create(['fingerprint' => ISSUE_FP_PAYMENT]);
    Issue::factory()->for($project)->ignored()->create(['fingerprint' => ISSUE_FP_LEDGER]);

    $this->actingAs($user)
        ->get(route('issues.index', ['current_team' => $team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('result.total', 1)
                ->where('result.issues.0.fingerprint', ISSUE_FP_PAYMENT)
                ->where('result.issues.0.status', 'regressed')
                ->etc()
            )
        );

    $this->actingAs($user)
        ->get(route('issues.index', ['current_team' => $team->slug, 'status' => 'ignored']))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('result.total', 1)
                ->where('result.issues.0.fingerprint', ISSUE_FP_LEDGER)
                ->etc()
            )
        );
});

test('search, service and environment narrow the list', function () {
    [$user, $team, $project] = issuesTeam();
    fakeIssuesClickHouse((string) $project->id);

    foreach ([['query' => 'ledger'], ['service' => 'billing'], ['environment' => 'staging']] as $filter) {
        $this->actingAs($user)
            ->get(route('issues.index', ['current_team' => $team->slug, ...$filter]))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('result.total', 1)
                    ->where('result.issues.0.fingerprint', ISSUE_FP_LEDGER)
                    ->etc()
                )
            );
    }
});

test('the list only ever asks for the team\'s own projects', function () {
    [$user, $team, $project] = issuesTeam();
    $other = Project::factory()->create();
    fakeIssuesClickHouse((string) $project->id);

    $this->actingAs($user)
        ->get(route('issues.index', ['current_team' => $team->slug]))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload->etc()));

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'AS WindowSeries')
        && str_contains(urldecode($request->url()), "param_projectIds=['{$project->id}']"));
    Http::assertNotSent(fn (Request $request): bool => str_contains(urldecode($request->url()), "'{$other->id}'"));
});

test('the issue page shows the headline, the latest event and its exception', function () {
    [$user, $team, $project] = issuesTeam();
    fakeIssuesClickHouse((string) $project->id);

    $this->actingAs($user)
        ->get(route('issues.show', ['current_team' => $team->slug, 'project' => $project->slug, 'fingerprint' => ISSUE_FP_PAYMENT]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('issues/Show')
            ->where('issue.fingerprint', ISSUE_FP_PAYMENT)
            ->where('issue.events', 900)
            ->where('issue.status', 'unresolved')
            ->where('issue.projectSlug', 'checkout')
            ->where('event.row.serviceName', 'checkout')
            ->where('event.hasOlder', true)
            ->where('event.hasNewer', false)
            ->where('event.expired', false)
            ->where('exception.type', 'App\\Exceptions\\PaymentFailed')
            ->where('exception.stacktrace', '#0 app/Payments/Charge.php:41 charge()')
            ->where('event.eventKey', '1234567890123')
            // The issue page is the one read that keeps the structured frames.
            ->where('event.row.logAttributes', fn ($attributes): bool => ($attributes['exception.frames'] ?? null) === '[{"filename":"app/Payments/Charge.php","lineno":41,"in_app":true}]')
            ->where('autofix.connected', false)
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('histogram.day.intervalSeconds', 3600)
                ->where('tags.tags.0.key', 'service.version')
                ->where('tags.tags.0.total', 40)
                ->where('tags.sampled', 40)
                ->where('recentEvents.rows.0.user', 'alice')
                ->etc()
            )
        );
});

test('an event is picked by position, and a malformed anchor is refused', function () {
    [$user, $team, $project] = issuesTeam();
    fakeIssuesClickHouse((string) $project->id);
    $url = fn (array $query): string => route('issues.show', ['current_team' => $team->slug, 'project' => $project->slug, 'fingerprint' => ISSUE_FP_PAYMENT, ...$query]);

    $this->actingAs($user)->get($url(['position' => 'older', 'event' => '2026-11-07 11:50:00.000000000']))->assertOk();

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'Timestamp < {anchor:DateTime64(9)}')
        && str_contains($request->body(), 'IssueFingerprint = {fingerprint:String}')
        && str_contains($request->body(), 'ProjectId IN {projectIds:Array(String)}'));

    $this->actingAs($user)->get($url(['position' => 'older', 'event' => "1' OR 1=1"]))->assertSessionHasErrors('event');
});

test('stepping from an event breaks timestamp ties on the event key', function () {
    [$user, $team, $project] = issuesTeam();
    fakeIssuesClickHouse((string) $project->id);
    $url = fn (array $query): string => route('issues.show', ['current_team' => $team->slug, 'project' => $project->slug, 'fingerprint' => ISSUE_FP_PAYMENT, ...$query]);

    $this->actingAs($user)->get($url(['position' => 'older', 'event' => '2026-11-07 11:50:00', 'key' => '42']))->assertOk();

    // Same-second events are ordered by the key, and the plain bound still prunes.
    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'Timestamp <= {anchor:DateTime64(9)} AND (Timestamp < {anchor:DateTime64(9)} OR cityHash64(')
        && str_contains(urldecode($request->url()), 'param_anchorKey=42')
        && str_contains($request->body(), 'ORDER BY Timestamp DESC, cityHash64('));

    // A key past UInt64 is dropped rather than handed to ClickHouse to choke on.
    $this->actingAs($user)->get($url(['position' => 'older', 'event' => '2026-11-07 11:50:00', 'key' => '99999999999999999999']))->assertOk();
    $this->actingAs($user)->get($url(['position' => 'older', 'event' => '2026-11-07 11:50:00', 'key' => 'abc']))->assertSessionHasErrors('key');
});

test('the log viewer leaves an event\'s structured issue attributes to the issue page', function () {
    [$user, $team, $project] = issuesTeam();
    fakeIssuesClickHouse((string) $project->id);

    $this->actingAs($user)
        ->get(route('logs.index', ['current_team' => $team->slug]))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('logs.rows.0.logAttributes', fn ($attributes): bool => ($attributes['exception.stacktrace'] ?? null) === '#0 app/Payments/Charge.php:41 charge()'
                && ! isset($attributes['exception.frames']))
            ->etc()
        ));
});

test('an issue resolved before its last event arrived is regressed, whatever the event says its time was', function () {
    [$user, $team, $project] = issuesTeam();
    fakeIssuesClickHouse((string) $project->id);

    // The payment issue's last event arrived at 11:50:02 (its own timestamp: 11:50).
    Issue::factory()->for($project)->resolved(Carbon::parse('2026-11-07 11:50:01'))->create(['fingerprint' => ISSUE_FP_PAYMENT]);
    Issue::factory()->for($project)->resolved(Carbon::parse('2026-11-07 11:55:00'))->create(['fingerprint' => ISSUE_FP_LEDGER]);

    $this->actingAs($user)
        ->get(route('issues.index', ['current_team' => $team->slug, 'status' => 'all']))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('result.issues.0.status', 'regressed')
            ->where('result.issues.1.status', 'resolved')
            ->etc()
        ));
});

test('an issue from another team\'s project is not found', function () {
    [$user, $team] = issuesTeam();
    $foreign = Project::factory()->create(['slug' => 'foreign']);
    fakeIssuesClickHouse((string) $foreign->id);

    $this->actingAs($user)
        ->get(route('issues.show', ['current_team' => $team->slug, 'project' => 'foreign', 'fingerprint' => ISSUE_FP_PAYMENT]))
        ->assertNotFound();

    $this->actingAs($user)
        ->patch(route('issues.update', ['current_team' => $team->slug, 'project' => 'foreign', 'fingerprint' => ISSUE_FP_PAYMENT]), ['status' => 'resolved'])
        ->assertNotFound();

    expect(Issue::count())->toBe(0);
});

test('an unknown fingerprint is not found', function () {
    [$user, $team, $project] = issuesTeam();
    Http::fake(fn () => Http::response(''));

    $this->actingAs($user)
        ->get(route('issues.show', ['current_team' => $team->slug, 'project' => $project->slug, 'fingerprint' => ISSUE_FP_PAYMENT]))
        ->assertNotFound();
});

test('resolving, ignoring and reopening record who did it and when', function () {
    [$user, $team, $project] = issuesTeam();
    fakeIssuesClickHouse((string) $project->id);
    $url = route('issues.update', ['current_team' => $team->slug, 'project' => $project->slug, 'fingerprint' => ISSUE_FP_PAYMENT]);

    $this->actingAs($user)->patch($url, ['status' => 'resolved'])->assertRedirect();

    $issue = Issue::sole();
    expect($issue->status)->toBe(IssueStatus::Resolved)
        ->and($issue->resolved_at?->toDateTimeString())->toBe('2026-11-07 12:00:00')
        ->and($issue->status_changed_by)->toBe($user->id)
        ->and($issue->project_id)->toBe($project->id);

    $this->actingAs($user)->patch($url, ['status' => 'unresolved'])->assertRedirect();

    expect($issue->fresh()->status)->toBe(IssueStatus::Unresolved)
        ->and($issue->fresh()->resolved_at)->toBeNull()
        ->and(Issue::count())->toBe(1);

    $this->actingAs($user)->patch($url, ['status' => 'regressed'])->assertSessionHasErrors('status');
});

test('the rail counts unresolved issues, regressed included, and caches the answer', function () {
    [$user, $team, $project] = issuesTeam();
    fakeIssuesClickHouse((string) $project->id);
    Issue::query()->create([
        'project_id' => $project->id,
        'fingerprint' => ISSUE_FP_PAYMENT,
        'status' => IssueStatus::Resolved,
        'resolved_at' => Carbon::parse('2026-11-06 12:00:00'),
    ]);

    $this->actingAs($user)
        ->get(route('issues.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('issueBadge')
            ->loadDeferredProps('nav', fn (Assert $reload) => $reload
                ->where('issueBadge.unresolved', 2)
                ->where('issueBadge.regressed', 1)
            )
        );

    Http::fake(fn () => Http::response('', 503));

    expect(app(IssueBadge::class)->forTeam($team))->toBe(['unresolved' => 2, 'regressed' => 1]);
});

test('the rail shows no count when issue storage cannot answer', function () {
    [, $team] = issuesTeam();
    Http::fake(fn () => Http::response('', 503));

    expect(app(IssueBadge::class)->forTeam($team))->toBeNull();
});
