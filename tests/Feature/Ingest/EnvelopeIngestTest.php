<?php

use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['clickhouse.host' => '127.0.0.1', 'clickhouse.port' => 8123]);

    $this->publicKey = 'bilis_pk_'.str_repeat('c', 40);
    $this->project = Project::factory()->create();
    $this->apiKey = ProjectApiKey::factory()->forProject($this->project)->withPublicKey($this->publicKey)->create();
});

/**
 * Post an envelope the way a client does: the header, its name and its shape
 * are all the wire protocol's, which is exactly what is under test here.
 */
function postEnvelope(string $publicKey, string $body, array $headers = []): TestResponse
{
    return test()->call('POST', '/api/1/envelope/', [], [], [], array_merge([
        'HTTP_X_SENTRY_AUTH' => "Sentry sentry_version=7, sentry_client=sentry.php/4.6.0, sentry_key={$publicKey}",
        'CONTENT_TYPE' => 'application/x-sentry-envelope',
    ], $headers), $body);
}

test('an exception envelope from a real client becomes one log record', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $body = file_get_contents(base_path('tests/Fixtures/envelope/laravel-exception.envelope'));

    postEnvelope($this->publicKey, $body)
        ->assertOk()
        ->assertJsonStructure(['id']);

    Http::assertSent(function (Request $request) {
        $rows = insertedRows($request);

        expect($rows)->toHaveCount(1);

        $row = $rows[0];

        expect($row)->toMatchArray([
            // The project is the public key's, never the id in the DSN path.
            'ProjectId' => (string) $this->project->id,
            // The thrown exception is the last of `exception.values`, not the
            // first: the chain arrives oldest cause first.
            'Body' => 'Illuminate\Database\QueryException: SQLSTATE[HY000]: server has gone away (Connection: mysql, SQL: select * from carts)',
            'SeverityNumber' => 17,
            'SeverityText' => 'ERROR',
            'ServiceName' => 'checkout',
            'TraceId' => '5b8efff798038103d269b633813fc60c',
            'SpanId' => 'eee19b7ec3c1b174',
            'EventName' => 'exception',
            'ScopeName' => 'sentry.php.laravel',
            'ScopeVersion' => '4.6.0',
            'ResourceSchemaUrl' => '',
            'ScopeSchemaUrl' => '',
        ]);

        expect($row['LogAttributes'])->toMatchArray([
            'exception.type' => 'Illuminate\Database\QueryException',
            'exception.mechanism' => 'generic',
            'exception.handled' => 'false',
            // The innermost application frame, which is what a reader wants
            // first and what an issue list would group on.
            'exception.origin' => 'app/Http/Controllers/CheckoutController.php:42 App\Http\Controllers\CheckoutController::store()',
            // The event's own fields, under the OpenTelemetry names the rest
            // of the table already uses.
            'event.id' => '9f2c1e7a4b3d4f5e8a6b7c8d9e0f1a2b',
            'service.version' => 'checkout@2026.8.1',
            'deployment.environment' => 'production',
            'transaction.name' => 'POST /checkout',
            'host.name' => 'web-01',
            'telemetry.sdk.name' => 'sentry.php.laravel',
            'telemetry.sdk.version' => '4.6.0',
            'telemetry.sdk.language' => 'php',
            'process.runtime.name' => 'php',
            'tag.service' => 'checkout',
            'extra.order_id' => '4711',
            'extra.retryable' => 'true',
            'user.id' => '42',
            'user.email' => 'buyer@example.com',
            'http.url' => 'https://shop.example.com/checkout',
            'http.request.method' => 'POST',
        ]);

        // Frames are rendered most recent first, the way a trace is read.
        expect($row['LogAttributes']['exception.stacktrace'])
            ->toStartWith('#0 app/Http/Controllers/CheckoutController.php:42')
            ->toContain('#1 public/index.php:17 require()');

        // Breadcrumbs keep their order too, newest at the top.
        expect($row['LogAttributes']['breadcrumbs'])
            ->toStartWith('[http] POST https://payments.example.com/charge');

        expect($row['Timestamp'])->toStartWith('2026-08-28 10:40:00.');

        return true;
    });
});

test('items other than events are counted as skipped, not rejected', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $body = implode("\n", [
        '{"event_id":"aaaa"}',
        '{"type":"session"}',
        '{"started":"2026-08-28T10:00:00Z"}',
        '{"type":"transaction"}',
        '{"transaction":"GET /"}',
    ])."\n";

    postEnvelope($this->publicKey, $body)->assertOk();

    Http::assertNothingSent();
});

test('an event without a length header runs to the end of its line', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $body = implode("\n", [
        '{"event_id":"aaaa"}',
        '{"type":"event","content_type":"application/json"}',
        '{"message":"Payment declined","level":"warning","server_name":"worker-3"}',
    ])."\n";

    postEnvelope($this->publicKey, $body)->assertOk();

    Http::assertSent(function (Request $request) {
        $row = insertedRows($request)[0];

        expect($row)->toMatchArray([
            'Body' => 'Payment declined',
            'SeverityNumber' => 13,
            'SeverityText' => 'WARN',
            'ServiceName' => 'worker-3',
            // Nothing was thrown, so this is a message, not an exception.
            'EventName' => '',
        ]);

        return true;
    });
});

test('a gzipped envelope is inflated', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $body = implode("\n", [
        '{"event_id":"aaaa"}',
        '{"type":"event"}',
        '{"message":"Compressed"}',
    ])."\n";

    postEnvelope($this->publicKey, (string) gzencode($body), ['HTTP_CONTENT_ENCODING' => 'gzip'])->assertOk();

    Http::assertSent(fn (Request $request) => insertedRows($request)[0]['Body'] === 'Compressed');
});

test('the public key may arrive as a query parameter', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $body = "{\"event_id\":\"aaaa\"}\n{\"type\":\"event\"}\n{\"message\":\"From the browser\"}\n";

    $this->call('POST', '/api/1/envelope/?sentry_key='.$this->publicKey, [], [], [], [], $body)->assertOk();

    Http::assertSent(fn (Request $request) => insertedRows($request)[0]['Body'] === 'From the browser');
});

test('the legacy store endpoint accepts a bare event', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->call('POST', '/api/1/store/', [], [], [], [
        'HTTP_X_SENTRY_AUTH' => "Sentry sentry_version=7, sentry_key={$this->publicKey}",
    ], '{"message":"Legacy","level":"info"}')->assertOk();

    Http::assertSent(fn (Request $request) => insertedRows($request)[0]['Body'] === 'Legacy');
});

test('a secret key is not accepted as a public key', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $plainTextKey = 'bilis_'.str_repeat('d', 40);
    ProjectApiKey::factory()->forProject($this->project)->withPlainKey($plainTextKey)->create();

    postEnvelope($plainTextKey, '{}')->assertUnauthorized();

    Http::assertNothingSent();
});

test('an unknown public key is rejected', function () {
    postEnvelope('bilis_pk_'.str_repeat('z', 40), '{}')->assertUnauthorized();
});

test('a malformed envelope is accepted and dropped, never a 400', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    postEnvelope($this->publicKey, 'not an envelope at all')->assertOk();

    Http::assertNothingSent();
});

test('a ClickHouse failure answers 503 with Retry-After', function () {
    $this->mock(ClickHouseClient::class)
        ->shouldReceive('insert')
        ->andThrow(new ClickHouseException('ClickHouse is down.'));

    $body = "{\"event_id\":\"aaaa\"}\n{\"type\":\"event\"}\n{\"message\":\"Boom\"}\n";

    postEnvelope($this->publicKey, $body)
        ->assertStatus(503)
        ->assertHeader('Retry-After', '5');
});

test('using a public key marks the credential as used', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    postEnvelope($this->publicKey, "{\"event_id\":\"a\"}\n{\"type\":\"event\"}\n{\"message\":\"Used\"}\n")->assertOk();

    expect($this->apiKey->fresh()->last_used_at)->not->toBeNull();
});
