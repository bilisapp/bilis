<?php

use App\Models\Project;
use App\Models\ProjectApiKey;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config([
        'clickhouse.host' => '127.0.0.1',
        'clickhouse.port' => 8123,
        'bilis.ingest.otlp_protobuf' => true,
    ]);

    $this->plainTextKey = 'bilis_'.str_repeat('a', 40);
    $this->project = Project::factory()->create();
    ProjectApiKey::factory()->forProject($this->project)->withPlainKey($this->plainTextKey)->create();

    // The body a real Go otlploghttp exporter sent; see tests/Fixtures/otlp.
    $this->body = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/otlp/otlp-logs-export.bin');
});

/**
 * POST a raw body with the given headers, bypassing the JSON helpers.
 */
function postOtlp(string $body, array $headers = []): TestResponse
{
    return test()->call('POST', '/api/v1/logs', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.test()->plainTextKey,
        'CONTENT_TYPE' => 'application/x-protobuf',
        ...$headers,
    ], $body);
}

test('a protobuf export is stored exactly like its json twin', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    postOtlp($this->body)->assertOk()->assertExactJson([]);

    Http::assertSent(function (Request $request) {
        $rows = insertedRows($request);

        expect($rows)->toHaveCount(2)
            ->and($rows[0])->toMatchArray([
                'ProjectId' => (string) $this->project->id,
                'Timestamp' => '2025-08-26 12:30:00.123456789',
                'TraceId' => '5b8efff798038103d269b633813fc60c',
                'SpanId' => 'eee19b7ec3c1b174',
                'TraceFlags' => 1,
                'SeverityNumber' => 17,
                'SeverityText' => 'ERROR',
                'ServiceName' => 'checkout',
                'Body' => 'Card declined for order 41902',
                'ScopeName' => 'checkout.payments',
                'ScopeVersion' => '1.4.0',
                'EventName' => 'payment.declined',
            ])
            ->and($rows[0]['LogAttributes'])->toMatchArray([
                'order.id' => '41902',
                'attempt' => '3',
                'retryable' => 'true',
                'amount' => '19.5',
            ])
            ->and($rows[1]['Body'])->toBe('Retrying in 8s');

        return true;
    });
});

test('a gzipped protobuf export is inflated first', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    postOtlp((string) gzencode($this->body), ['HTTP_CONTENT_ENCODING' => 'gzip'])
        ->assertOk()
        ->assertExactJson([]);

    Http::assertSent(fn (Request $request) => count(insertedRows($request)) === 2);
});

test('the protobuf encoding can be turned off', function () {
    config(['bilis.ingest.otlp_protobuf' => false]);

    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    postOtlp($this->body)
        ->assertStatus(415)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'OTEL_EXPORTER_OTLP_PROTOCOL=http/json'));

    Http::assertNothingSent();
});

test('a malformed protobuf body is skipped, never rejected', function (string $body) {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    postOtlp($body)
        ->assertOk()
        ->assertJsonPath('partialSuccess.errorMessage', 'Request body could not be read as an OTLP ExportLogsServiceRequest.');

    Http::assertNothingSent();
})->with([
    'truncated' => [fn () => substr((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/otlp/otlp-logs-export.bin'), 0, 120)],
    'json in a protobuf wrapper' => ['{"resourceLogs":[]}'],
    'random bytes' => ["\xFF\xFE\xFD\xFC\xFB"],
]);

test('a body that claims gzip but is not gzipped is skipped, never rejected', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    postOtlp($this->body, ['HTTP_CONTENT_ENCODING' => 'gzip'])
        ->assertOk()
        ->assertJsonPath('partialSuccess.errorMessage', 'Request body could not be read as an OTLP ExportLogsServiceRequest.');

    Http::assertNothingSent();
});

test('a compression Bilis cannot undo is named in a 415', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    postOtlp($this->body, ['HTTP_CONTENT_ENCODING' => 'zstd'])
        ->assertStatus(415)
        ->assertJson(['message' => 'Content-Encoding zstd is not supported. Send the body uncompressed or with gzip or deflate.']);

    Http::assertNothingSent();
});

test('a decompressed body larger than the cap is refused rather than half read', function () {
    config(['bilis.ingest.max_decompressed_bytes' => 512]);

    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    // Compresses to a few hundred bytes and expands well past the cap.
    $payload = (string) json_encode(['resourceLogs' => []]).str_repeat(' ', 4096);

    postOtlp((string) gzencode($payload), ['HTTP_CONTENT_ENCODING' => 'gzip'])
        ->assertOk()
        ->assertJsonPath('partialSuccess.errorMessage', 'Request body could not be read as an OTLP ExportLogsServiceRequest.');

    Http::assertNothingSent();
});

test('a record with invalid UTF-8 does not fail its batch', function () {
    // Two records, the first carrying a byte no JSON encoder accepts. Before
    // the decoder scrubbed strings, json_encode threw at insert time, the whole
    // batch came back 503, and a real exporter retried the poison pill forever.
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    // All fields here are small, so a single-byte length prefix is exact.
    $field = fn (int $f, string $b): string => chr($f << 3 | 2).chr(strlen($b)).$b;
    $bad = $field(5, $field(1, "declined\xFF\xFE"));    // LogRecord{body{stringValue: invalid}}
    $good = $field(5, $field(1, 'retrying'));            // LogRecord{body{stringValue: "retrying"}}
    // Each record is its own repeated log_records entry (field 2).
    $scopeLogs = $field(2, $bad).$field(2, $good);
    $body = $field(1, $field(2, $scopeLogs));            // request → resourceLogs → scopeLogs

    postOtlp($body)->assertOk()->assertExactJson([]);

    Http::assertSent(function (Request $request) {
        $rows = insertedRows($request);

        expect($rows)->toHaveCount(2)
            ->and($rows[1]['Body'])->toBe('retrying')                       // the good record is intact
            ->and(mb_check_encoding($rows[0]['Body'], 'UTF-8'))->toBeTrue() // the bad one was scrubbed, not dropped
            ->and($rows[0]['Body'])->toStartWith('declined');

        return true;
    });
});

test('an unauthenticated protobuf export is still rejected', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    test()->call('POST', '/api/v1/logs', [], [], [], [
        'CONTENT_TYPE' => 'application/x-protobuf',
    ], $this->body)->assertUnauthorized();

    Http::assertNothingSent();
});
