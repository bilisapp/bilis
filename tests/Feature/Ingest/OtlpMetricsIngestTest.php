<?php

use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config([
        'clickhouse.host' => '127.0.0.1',
        'clickhouse.port' => 8123,
        'bilis.ingest.otlp_protobuf' => true,
    ]);

    $this->plainTextKey = 'bilis_'.str_repeat('m', 40);
    $this->project = Project::factory()->create();
    ProjectApiKey::factory()->forProject($this->project)->withPlainKey($this->plainTextKey)->create();
});

/**
 * POST a raw body to the metrics endpoint.
 *
 * @param  array<string, string>  $headers
 */
function postMetrics(string $body, array $headers = []): TestResponse
{
    return test()->call('POST', '/api/v1/metrics', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.test()->plainTextKey,
        'CONTENT_TYPE' => 'application/json',
        ...$headers,
    ], $body);
}

/**
 * The fixture bytes captured from a real exporter.
 */
function metricsFixtureBody(string $name, string $extension): string
{
    return (string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/otlp/{$name}.{$extension}");
}

/**
 * The rows sent to one table, keyed by the INSERT they came in.
 *
 * @return list<array<string, mixed>>
 */
function metricRowsSentTo(string $table): array
{
    $rows = [];

    Http::assertSent(function (Request $request) use ($table, &$rows) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if (($query['query'] ?? '') === "INSERT INTO {$table} FORMAT JSONEachRow") {
            $rows = [...$rows, ...insertedRows($request)];
        }

        return true;
    });

    return $rows;
}

test('a json export is written to one table per metric type, for the key\'s project', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    postMetrics(metricsFixtureBody('otlp-metrics-export', 'json'))->assertOk()->assertExactJson([]);

    $sums = metricRowsSentTo('otel_metrics_sum');
    $histograms = metricRowsSentTo('otel_metrics_histogram');

    expect($sums)->toHaveCount(3)
        ->and(metricRowsSentTo('otel_metrics_gauge'))->toHaveCount(1)
        ->and($histograms)->toHaveCount(1)
        ->and(metricRowsSentTo('otel_metrics_exponential_histogram'))->toHaveCount(1)
        // No summary in an SDK export, so no insert for that table at all.
        ->and(metricRowsSentTo('otel_metrics_summary'))->toBe([])
        ->and(array_unique(array_column($sums, 'ProjectId')))->toBe([(string) $this->project->id])
        ->and($sums[0]['ServiceName'])->toBe('checkout')
        // Map columns reach ClickHouse as objects, the empty one included.
        ->and($sums[0]['ScopeAttributes'])->toBe([])
        ->and($histograms[0]['ExplicitBounds'])->toBe([0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1]);

    Http::assertSent(fn (Request $request) => str_contains($request->body(), '"ScopeAttributes":{}'));
    Http::assertSentCount(4);
});

test('a protobuf export, gzipped, lands as the same rows and is answered in protobuf', function () {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    $response = postMetrics((string) gzencode(metricsFixtureBody('otlp-metrics-kitchen-sink', 'bin')), [
        'CONTENT_TYPE' => 'application/x-protobuf',
        'HTTP_CONTENT_ENCODING' => 'gzip',
    ])->assertOk();

    // The NaN gauge point and the point with no time are refused by count.
    expect(decodeOtlpResponse((string) $response->getContent()))
        ->toBe(['rejected' => 2, 'errorMessage' => 'Some data points could not be stored and were skipped.'])
        ->and(metricRowsSentTo('otel_metrics_summary'))->toHaveCount(1)
        ->and(metricRowsSentTo('otel_metrics_gauge'))->toHaveCount(2);
});

test('rejected data points are reported through partialSuccess, never a 400', function (mixed $payload) {
    Http::fake(['127.0.0.1:8123/*' => Http::response('')]);

    postMetrics(is_string($payload) ? $payload : (string) json_encode($payload))
        ->assertOk()
        ->assertJsonPath('partialSuccess.rejectedDataPoints', fn (int $rejected): bool => $rejected >= 0);
})->with([
    'not json' => ['{"resourceMetrics": ['],
    'resourceMetrics not a list' => [['resourceMetrics' => 'nope']],
    'a NaN point' => [['resourceMetrics' => [['scopeMetrics' => [['metrics' => [[
        'name' => 'x',
        'gauge' => ['dataPoints' => [['timeUnixNano' => '1767225600000000000', 'asDouble' => 'NaN']]],
    ]]]]]]]],
]);

test('a clickhouse failure answers 503 with a retry hint, never 4xx', function () {
    $this->mock(ClickHouseClient::class, function ($mock) {
        $mock->shouldReceive('insert')->andThrow(ClickHouseException::fromInvalidResponse('ClickHouse is unavailable.'));
    });

    postMetrics(metricsFixtureBody('otlp-metrics-export', 'json'))
        ->assertStatus(503)
        ->assertHeader('Retry-After', '5')
        ->assertJsonPath('message', 'Metric storage is temporarily unavailable. Please retry.');
});

test('an unsupported content encoding is refused with a message naming what works', function () {
    postMetrics('compressed', ['HTTP_CONTENT_ENCODING' => 'zstd'])->assertStatus(415);
});

test('protobuf is refused with a pointer to json when this instance turned it off', function () {
    config(['bilis.ingest.otlp_protobuf' => false]);

    postMetrics(metricsFixtureBody('otlp-metrics-export', 'bin'), ['CONTENT_TYPE' => 'application/x-protobuf'])
        ->assertStatus(415)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'http/json'));
});

test('the endpoint refuses a request with no key or an unknown one', function () {
    $this->postJson('/api/v1/metrics', [])->assertUnauthorized();

    $this->withHeaders(['Authorization' => 'Bearer bilis_'.str_repeat('z', 40)])
        ->postJson('/api/v1/metrics', [])
        ->assertUnauthorized();
});
