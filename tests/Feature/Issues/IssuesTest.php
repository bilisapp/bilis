<?php

use App\Enums\IssueStatus;
use App\Enums\TeamRole;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
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

const PAYMENT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const LEDGER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

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
                ['ProjectId' => $projectId, 'IssueFingerprint' => PAYMENT, 'WindowEvents' => '40', 'WindowUsers' => '12', 'WindowSeries' => [[Carbon::parse('2026-11-07 11:00')->getTimestamp(), '40']]],
                ['ProjectId' => $projectId, 'IssueFingerprint' => LEDGER, 'WindowEvents' => '3', 'WindowUsers' => '0', 'WindowSeries' => [[Carbon::parse('2026-11-07 09:00')->getTimestamp(), '3']]],
            ]),
            str_contains($body, 'AS IssueFirstSeen') => issueRows([
                [
                    'ProjectId' => $projectId, 'IssueFingerprint' => PAYMENT,
                    'IssueFirstSeen' => ms('2026-10-01 08:00'), 'IssueLastSeen' => ms('2026-11-07 11:50'),
                    'IssueEvents' => '900', 'IssueUsers' => '120', 'IssueServices' => ['checkout'], 'IssueEnvironments' => ['production'],
                    'IssueType' => 'App\\Exceptions\\PaymentFailed', 'IssueMessage' => 'Card was declined', 'IssueOrigin' => 'app/Payments/Charge.php:41 charge()',
                    'IssueService' => 'checkout', 'IssueLastRelease' => '1.4.0', 'IssueFirstRelease' => '1.2.0',
                ],
                [
                    'ProjectId' => $projectId, 'IssueFingerprint' => LEDGER,
                    'IssueFirstSeen' => ms('2026-11-07 09:00'), 'IssueLastSeen' => ms('2026-11-07 09:10'),
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
                ['TagKey' => 'service.version', 'TagValue' => '1.4.0', 'TagCount' => '30', 'TagTotal' => '40'],
                ['TagKey' => 'service.version', 'TagValue' => '1.3.0', 'TagCount' => '10', 'TagTotal' => '40'],
            ]),
            str_contains($body, 'FROM otel_logs') => issueRows([[
                'ProjectId' => $projectId, 'Timestamp' => '2026-11-07 11:50:00.000000000', 'TraceId' => '', 'SpanId' => '',
                'SeverityText' => 'ERROR', 'SeverityNumber' => 17, 'ServiceName' => 'checkout',
                'Body' => 'App\\Exceptions\\PaymentFailed: Card was declined', 'ScopeName' => '', 'ScopeVersion' => '',
                'ResourceAttributes' => [], 'LogAttributes' => [
                    'exception.type' => 'App\\Exceptions\\PaymentFailed',
                    'exception.message' => 'Card was declined',
                    'exception.stacktrace' => '#0 app/Payments/Charge.php:41 charge()',
                ],
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
                ->where('result.issues.0.fingerprint', PAYMENT)
                ->where('result.issues.0.type', 'App\\Exceptions\\PaymentFailed')
                ->where('result.issues.0.projectSlug', 'checkout')
                ->where('result.issues.0.windowEvents', 40)
                ->where('result.issues.0.users', 120)
                ->where('result.issues.0.status', 'unresolved')
                ->where('result.issues.0.isNew', false)
                ->where('result.issues.1.isNew', true)
                ->where('result.services', ['billing', 'checkout'])
                ->where('result.environments', ['production', 'staging'])
                ->has('result.issues.0.series', 24)
                ->etc()
            )
        );
});

test('the status filter reads regressed as unresolved and hides ignored issues', function () {
    [$user, $team, $project] = issuesTeam();
    fakeIssuesClickHouse((string) $project->id);

    // Resolved before its last event at 11:50: regressed.
    Issue::factory()->for($project)->resolved(Carbon::parse('2026-11-07 10:00'))->create(['fingerprint' => PAYMENT]);
    Issue::factory()->for($project)->ignored()->create(['fingerprint' => LEDGER]);

    $this->actingAs($user)
        ->get(route('issues.index', ['current_team' => $team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('result.total', 1)
                ->where('result.issues.0.fingerprint', PAYMENT)
                ->where('result.issues.0.status', 'regressed')
                ->etc()
            )
        );

    $this->actingAs($user)
        ->get(route('issues.index', ['current_team' => $team->slug, 'status' => 'ignored']))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('result.total', 1)
                ->where('result.issues.0.fingerprint', LEDGER)
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
                    ->where('result.issues.0.fingerprint', LEDGER)
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
        ->get(route('issues.show', ['current_team' => $team->slug, 'project' => $project->slug, 'fingerprint' => PAYMENT]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('issues/Show')
            ->where('issue.fingerprint', PAYMENT)
            ->where('issue.events', 900)
            ->where('issue.status', 'unresolved')
            ->where('issue.projectSlug', 'checkout')
            ->where('event.row.serviceName', 'checkout')
            ->where('event.hasOlder', true)
            ->where('event.hasNewer', false)
            ->where('event.expired', false)
            ->where('exception.type', 'App\\Exceptions\\PaymentFailed')
            ->where('exception.stacktrace', '#0 app/Payments/Charge.php:41 charge()')
            ->where('autofix.connected', false)
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('histogram.day.intervalSeconds', 3600)
                ->where('tags.tags.0.key', 'service.version')
                ->where('tags.tags.0.total', 40)
                ->where('recentEvents.rows.0.user', 'alice')
                ->etc()
            )
        );
});

test('an event is picked by position, and a malformed anchor is refused', function () {
    [$user, $team, $project] = issuesTeam();
    fakeIssuesClickHouse((string) $project->id);
    $url = fn (array $query): string => route('issues.show', ['current_team' => $team->slug, 'project' => $project->slug, 'fingerprint' => PAYMENT, ...$query]);

    $this->actingAs($user)->get($url(['position' => 'older', 'event' => '2026-11-07 11:50:00.000000000']))->assertOk();

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'Timestamp < {anchor:DateTime64(9)}')
        && str_contains($request->body(), 'IssueFingerprint = {fingerprint:String}')
        && str_contains($request->body(), 'ProjectId IN {projectIds:Array(String)}'));

    $this->actingAs($user)->get($url(['position' => 'older', 'event' => "1' OR 1=1"]))->assertSessionHasErrors('event');
});

test('an issue from another team\'s project is not found', function () {
    [$user, $team] = issuesTeam();
    $foreign = Project::factory()->create(['slug' => 'foreign']);
    fakeIssuesClickHouse((string) $foreign->id);

    $this->actingAs($user)
        ->get(route('issues.show', ['current_team' => $team->slug, 'project' => 'foreign', 'fingerprint' => PAYMENT]))
        ->assertNotFound();

    $this->actingAs($user)
        ->patch(route('issues.update', ['current_team' => $team->slug, 'project' => 'foreign', 'fingerprint' => PAYMENT]), ['status' => 'resolved'])
        ->assertNotFound();

    expect(Issue::count())->toBe(0);
});

test('an unknown fingerprint is not found', function () {
    [$user, $team, $project] = issuesTeam();
    Http::fake(fn () => Http::response(''));

    $this->actingAs($user)
        ->get(route('issues.show', ['current_team' => $team->slug, 'project' => $project->slug, 'fingerprint' => PAYMENT]))
        ->assertNotFound();
});

test('resolving, ignoring and reopening record who did it and when', function () {
    [$user, $team, $project] = issuesTeam();
    fakeIssuesClickHouse((string) $project->id);
    $url = route('issues.update', ['current_team' => $team->slug, 'project' => $project->slug, 'fingerprint' => PAYMENT]);

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
