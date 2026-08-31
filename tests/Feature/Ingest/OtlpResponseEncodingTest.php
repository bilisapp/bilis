<?php

use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Services\Ingest\OtlpResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * OTLP/HTTP is symmetric: the response carries the encoding of the request.
 *
 * Bilis used to answer every export in JSON whatever arrived, which stored the
 * data correctly and returned 200 — and made every spec-compliant client log a
 * wire-format error after each batch, because it was parsing `{}` as protobuf.
 * The export looked broken to the operator and fine to the server, so nothing
 * here caught it until Bilis was pointed at itself.
 */
beforeEach(function () {
    config([
        'clickhouse.host' => '127.0.0.1',
        'clickhouse.port' => 8123,
        'bilis.ingest.otlp_protobuf' => true,
    ]);

    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $this->plainTextKey = 'bilis_'.str_repeat('c', 40);
    $this->project = Project::factory()->create();
    ProjectApiKey::factory()->forProject($this->project)->withPlainKey($this->plainTextKey)->create();

    $this->protobufLogs = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/otlp/otlp-logs-export.bin');
});

/**
 * POST a raw body to one of the two OTLP endpoints.
 */
function postSignal(string $path, string $body, string $contentType): TestResponse
{
    return test()->call('POST', $path, [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.test()->plainTextKey,
        'CONTENT_TYPE' => $contentType,
    ], $body);
}

test('a protobuf export is answered in protobuf', function (string $path, string $body) {
    $response = postSignal($path, $body, 'application/x-protobuf')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe(OtlpResponse::PROTOBUF_CONTENT_TYPE)
        // The empty message, which is what a complete success is on the wire.
        ->and($response->getContent())->toBe('')
        ->and(decodeOtlpResponse($response->getContent()))->toBe([]);
})->with([
    'logs' => fn () => ['/api/v1/logs', test()->protobufLogs],
    'traces' => fn () => ['/api/v1/traces', ''],
]);

test('a json export is still answered in json', function (string $path) {
    $response = postSignal($path, (string) json_encode([]), 'application/json')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/json')
        ->and($response->getContent())->toBe('{}');
})->with(['/api/v1/logs', '/api/v1/traces']);

test('a partial success is encoded in the request encoding too', function () {
    /*
     * An unreadable protobuf body: rejected whole, reported through
     * partialSuccess, and — the point of this test — reported as protobuf.
     */
    $response = postSignal('/api/v1/traces', 'definitely not protobuf', 'application/x-protobuf')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe(OtlpResponse::PROTOBUF_CONTENT_TYPE)
        ->and($response->getContent())->not->toBe('')
        ->and(decodeOtlpResponse($response->getContent()))
        ->toBe(['errorMessage' => 'Request body could not be read as an OTLP ExportTraceServiceRequest.']);

    // The same rejection over JSON keeps the JSON field name it always had.
    postSignal('/api/v1/traces', 'definitely not json', 'application/json')
        ->assertOk()
        ->assertJsonPath('partialSuccess.rejectedSpans', 0)
        ->assertJsonPath('partialSuccess.errorMessage', 'Request body could not be read as an OTLP ExportTraceServiceRequest.');
});

test('errors stay json, because they carry a message OTLP has no field for', function () {
    config(['bilis.ingest.otlp_protobuf' => false]);

    postSignal('/api/v1/logs', $this->protobufLogs, 'application/x-protobuf')
        ->assertStatus(415)
        ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'OTEL_EXPORTER_OTLP_PROTOCOL=http/json'));
});
