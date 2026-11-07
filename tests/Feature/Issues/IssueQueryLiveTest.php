<?php

use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use App\Services\Ingest\LogWriter;
use App\Services\Issues\IssueQuery;
use App\Services\Logs\LogQuery;
use Illuminate\Support\Carbon;

/*
 * The issue SQL against a real server: LogWriter stamps the fingerprint, the
 * two materialized views fold it into issue_summary / issue_hourly, and every
 * read re-aggregates them (SCHEMA.md R17-R18). A faked HTTP call accepts any
 * statement at all, so only this proves the aggregates, aliases and
 * ARRAY JOIN are legal and answer what they claim.
 *
 * Skipped unless a server with the issue tables is reachable. Rows go under a
 * throwaway ProjectId and are deleted afterwards.
 */
beforeEach(function () {
    $client = app(ClickHouseClient::class);

    try {
        $client->select('SELECT 1 FROM issue_summary LIMIT 1');
    } catch (ClickHouseException) {
        $this->markTestSkipped('No ClickHouse with the issue tables reachable; set CLICKHOUSE_* to run the issue query test.');
    }

    $this->client = $client;
    $this->projectId = '7'.random_int(100000, 999999);
    $this->now = Carbon::now('UTC');

    $writer = app(LogWriter::class);

    /*
     * Two writes, so the views see more than one insert block — the case a
     * read that forgot to re-aggregate gets wrong.
     */
    $writer->write([
        liveIssueRow($this->projectId, $this->now->clone()->subHours(3), 'checkout', envelopeAttributes('alice', '1.0.0')),
        liveIssueRow($this->projectId, $this->now->clone()->subHours(2), 'checkout', envelopeAttributes('bob', '1.0.0')),
        liveIssueRow($this->projectId, $this->now->clone()->subMinutes(30), 'billing', [], body: "RuntimeException: Ledger locked\n#0 /srv/app/Ledger.php(12): App\\Ledger->lock()\n#1 {main}"),
        // An error line with no exception, and an exception logged at INFO: neither is an issue.
        liveIssueRow($this->projectId, $this->now->clone()->subMinutes(20), 'checkout', [], body: 'Payment declined for order 42'),
        liveIssueRow($this->projectId, $this->now->clone()->subMinutes(10), 'checkout', envelopeAttributes('carol', '1.0.0'), severity: 9),
    ]);
    $writer->write([
        liveIssueRow($this->projectId, $this->now->clone()->subMinutes(5), 'checkout', envelopeAttributes('alice', '1.1.0')),
    ]);

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $rows = $client->select('SELECT sum(Events) AS c FROM issue_summary WHERE ProjectId = {p:String}', ['p' => $this->projectId]);

        if ((int) ($rows[0]['c'] ?? 0) >= 4) {
            break;
        }

        usleep(100_000);
    }
});

afterEach(function () {
    if (! isset($this->client)) {
        return;
    }

    foreach (['otel_logs', 'issue_summary', 'issue_hourly'] as $table) {
        $this->client->execute(sprintf("ALTER TABLE %s DELETE WHERE ProjectId = '%s'", $table, $this->projectId));
    }
});

/**
 * @return array<string, string>
 */
function envelopeAttributes(string $user, string $release): array
{
    return [
        'exception.type' => 'App\\Exceptions\\PaymentFailed',
        'exception.message' => 'Card 4242 was declined',
        'exception.stacktrace' => "#0 app/Payments/Charge.php:41 charge()\n#1 app/Http/CheckoutController.php:88 store()",
        'exception.origin' => 'app/Payments/Charge.php:41 charge()',
        'user.id' => $user,
        'service.version' => $release,
        'deployment.environment' => 'production',
    ];
}

/**
 * @param  array<string, string>  $attributes
 * @return array<string, mixed>
 */
function liveIssueRow(string $projectId, Carbon $at, string $service, array $attributes, string $body = 'App\\Exceptions\\PaymentFailed: Card 4242 was declined', int $severity = 17): array
{
    return [
        'Timestamp' => $at->format('Y-m-d H:i:s.u'),
        'TraceId' => '',
        'SpanId' => '',
        'TraceFlags' => 0,
        'SeverityText' => $severity >= 17 ? 'ERROR' : 'INFO',
        'SeverityNumber' => $severity,
        'ServiceName' => $service,
        'Body' => $body,
        'ResourceSchemaUrl' => '',
        'ResourceAttributes' => [],
        'ScopeSchemaUrl' => '',
        'ScopeName' => '',
        'ScopeVersion' => '',
        'ScopeAttributes' => [],
        'LogAttributes' => $attributes,
        'EventName' => '',
        'ProjectId' => $projectId,
    ];
}

test('the window nominates each issue once, with exact counts and users', function () {
    $candidates = app(IssueQuery::class)->candidates([$this->projectId], $this->now->clone()->subDay(), $this->now);

    expect($candidates)->toHaveCount(2);

    $byEvents = collect($candidates)->keyBy('windowEvents');

    // Three PaymentFailed events from two users, across two insert blocks.
    expect($byEvents[3]['windowUsers'])->toBe(2)
        ->and(array_sum($byEvents[3]['series']))->toBe(3)
        ->and($byEvents[1]['windowUsers'])->toBe(0);
});

test('a headline re-aggregates every block and keeps the newest values', function () {
    $query = app(IssueQuery::class);
    $candidates = $query->candidates([$this->projectId], $this->now->clone()->subDay(), $this->now);
    $summaries = $query->summaries([$this->projectId], array_column($candidates, 'fingerprint'));

    $payment = collect($summaries)->firstWhere('type', 'App\\Exceptions\\PaymentFailed');
    $ledger = collect($summaries)->firstWhere('service', 'billing');

    expect($payment)->not->toBeNull()
        ->and($payment['events'])->toBe(3)
        ->and($payment['users'])->toBe(2)
        ->and($payment['message'])->toBe('Card 4242 was declined')
        ->and($payment['origin'])->toBe('app/Payments/Charge.php:41 charge()')
        ->and($payment['firstRelease'])->toBe('1.0.0')
        ->and($payment['lastRelease'])->toBe('1.1.0')
        ->and($payment['environments'])->toBe(['production'])
        ->and($payment['lastSeen'])->toBeGreaterThan($payment['firstSeen']);

    // The body-only exception falls back to reading its type, message and first frame out of the text.
    expect($ledger)->not->toBeNull()
        ->and($ledger['type'])->toBe('RuntimeException')
        ->and($ledger['message'])->toBe('RuntimeException: Ledger locked')
        ->and($ledger['origin'])->toBe('/srv/app/Ledger.php(12): App\\Ledger->lock()');
});

test('the histogram, events, navigation and tags read one issue', function () {
    $query = app(IssueQuery::class);
    $logs = app(LogQuery::class);
    $candidates = $query->candidates([$this->projectId], $this->now->clone()->subDay(), $this->now);
    $fingerprint = collect($candidates)->firstWhere('windowEvents', 3)['fingerprint'];
    $from = $this->now->clone()->subDay();

    $histogram = $query->histogram($this->projectId, $fingerprint, $from, $this->now);
    expect(array_sum(array_column($histogram['buckets'], 'events')))->toBe(3)
        ->and($histogram['intervalSeconds'])->toBe(3600);

    $latest = $logs->issueEvent($this->projectId, $fingerprint, $from, $this->now);
    expect($latest['row']['logAttributes']['service.version'])->toBe('1.1.0')
        ->and($latest['hasNewer'])->toBeFalse()
        ->and($latest['hasOlder'])->toBeTrue();

    $older = $logs->issueEvent($this->projectId, $fingerprint, $from, $this->now, 'older', $latest['row']['timestamp']);
    expect($older['row']['logAttributes']['user.id'])->toBe('bob')
        ->and($older['hasNewer'])->toBeTrue();

    $oldest = $logs->issueEvent($this->projectId, $fingerprint, $from, $this->now, 'oldest');
    expect($oldest['row']['logAttributes']['user.id'])->toBe('alice')
        ->and($oldest['hasOlder'])->toBeFalse();

    $at = $logs->issueEvent($this->projectId, $fingerprint, $from, $this->now, 'at', $older['row']['timestamp']);
    expect($at['row']['timestamp'])->toBe($older['row']['timestamp']);

    $events = $logs->issueEvents($this->projectId, $fingerprint, $from, $this->now);
    expect($events['rows'])->toHaveCount(3)
        ->and($events['rows'][0]['release'])->toBe('1.1.0')
        ->and($events['rows'][0]['user'])->toBe('alice')
        ->and($events['rows'][0]['message'])->toBe('Card 4242 was declined');

    $tags = collect($logs->issueTags($this->projectId, $fingerprint, $from, $this->now)['tags'])->keyBy('key');
    expect($tags['service.version']['total'])->toBe(3)
        ->and($tags['service.version']['values'][0])->toBe(['value' => '1.0.0', 'count' => 2])
        ->and($tags['user']['values'][0])->toBe(['value' => 'alice', 'count' => 2])
        ->and($tags['service.name']['values'])->toBe([['value' => 'checkout', 'count' => 3]])
        ->and($tags->has('browser.name'))->toBeFalse();
});

test('a project with issues says so and an unknown one does not', function () {
    $query = app(IssueQuery::class);

    expect($query->hasAnyIssues([$this->projectId]))->toBeTrue()
        ->and($query->hasAnyIssues(['1'.random_int(100000, 999999)]))->toBeFalse();
});
