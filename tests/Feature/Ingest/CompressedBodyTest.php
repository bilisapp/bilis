<?php

use App\Models\Project;
use App\Models\ProjectApiKey;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Nothing in front of the application inflates a request body — not Traefik,
 * not FrankenPHP — and the Collector's otlphttp exporter compresses by
 * default. Before these endpoints understood `Content-Encoding`, such an
 * export was acknowledged and thrown away.
 */
beforeEach(function () {
    config(['clickhouse.host' => '127.0.0.1', 'clickhouse.port' => 8123]);

    $this->plainTextKey = 'bilis_'.str_repeat('b', 40);
    $this->project = Project::factory()->create();
    ProjectApiKey::factory()->forProject($this->project)->withPlainKey($this->plainTextKey)->create();
});

/**
 * POST a raw body to an ingest endpoint with the given headers.
 */
function postBody(string $uri, string $body, array $headers = []): TestResponse
{
    return test()->call('POST', $uri, [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.test()->plainTextKey,
        'CONTENT_TYPE' => 'application/json',
        ...$headers,
    ], $body);
}

test('the simple endpoint accepts a gzipped body', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $payload = (string) json_encode([
        ['message' => 'Card declined', 'level' => 'error', 'service' => 'checkout'],
        ['message' => 'Retrying in 8s', 'level' => 'warn', 'service' => 'checkout'],
    ]);

    postBody('/api/v1/ingest', (string) gzencode($payload), ['HTTP_CONTENT_ENCODING' => 'gzip'])
        ->assertAccepted()
        ->assertJson(['accepted' => 2, 'skipped' => 0]);

    Http::assertSent(function (Request $request) {
        expect(insertedRows($request))->toHaveCount(2);

        return true;
    });
});

test('the otlp endpoint accepts a gzipped json body', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $payload = (string) json_encode(['resourceLogs' => [[
        'resource' => ['attributes' => [['key' => 'service.name', 'value' => ['stringValue' => 'checkout']]]],
        'scopeLogs' => [['logRecords' => [[
            'timeUnixNano' => '1756211400123456789',
            'severityNumber' => 17,
            'body' => ['stringValue' => 'Card declined'],
        ]]]],
    ]]]);

    postBody('/api/v1/logs', (string) gzencode($payload), ['HTTP_CONTENT_ENCODING' => 'gzip'])
        ->assertOk()
        ->assertExactJson([]);

    Http::assertSent(function (Request $request) {
        expect(insertedRows($request)[0])->toMatchArray([
            'ServiceName' => 'checkout',
            'Body' => 'Card declined',
        ]);

        return true;
    });
});

test('a deflated body is accepted in both of its spellings', function (string $spelling) {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $payload = (string) json_encode(['message' => 'Card declined', 'service' => 'checkout']);

    // The HTTP spec says zlib wrapped; a good deal of the wild says raw.
    $body = $spelling === 'zlib' ? (string) gzcompress($payload) : (string) gzdeflate($payload);

    postBody('/api/v1/ingest', $body, ['HTTP_CONTENT_ENCODING' => 'deflate'])
        ->assertAccepted()
        ->assertJson(['accepted' => 1]);
})->with(['zlib', 'raw']);

test('an identity encoding is read as the plain body it is', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    postBody(
        '/api/v1/ingest',
        (string) json_encode(['message' => 'Card declined']),
        ['HTTP_CONTENT_ENCODING' => 'identity'],
    )->assertAccepted()->assertJson(['accepted' => 1]);
});

test('a body that claims gzip but is not gzipped is skipped, never rejected', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    postBody('/api/v1/ingest', (string) json_encode(['message' => 'Card declined']), ['HTTP_CONTENT_ENCODING' => 'gzip'])
        ->assertAccepted()
        ->assertJson(['accepted' => 0, 'skipped' => 1]);

    Http::assertNothingSent();
});

test('a compression Bilis cannot undo is named in a 415', function (string $encoding) {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    postBody('/api/v1/ingest', 'whatever', ['HTTP_CONTENT_ENCODING' => $encoding])
        ->assertStatus(415)
        ->assertJson(['message' => "Content-Encoding {$encoding} is not supported. Send the body uncompressed or with gzip or deflate."]);

    Http::assertNothingSent();
})->with(['zstd', 'snappy', 'br', 'lz4', 'gzip, gzip']);
