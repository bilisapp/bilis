<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesOtlpExport;
use App\Http\Controllers\Controller;
use App\Services\Ingest\MappedMetrics;
use App\Services\Ingest\MetricWriter;
use App\Services\Ingest\OtlpMetricsMapper;
use App\Services\Ingest\Protobuf\OtlpMetricsProtobufDecoder;

/**
 * The OTLP/HTTP metrics endpoint, in both the JSON and protobuf encodings.
 *
 * The third twin of {@see OtlpLogController}: data points that cannot be
 * stored are reported through `rejectedDataPoints` and the rest of the export
 * is kept. All five OTLP metric types are accepted, each into its own
 * `otel_metrics_*` table (SCHEMA.md §2.5).
 */
class OtlpMetricController extends Controller
{
    use HandlesOtlpExport;

    public function __construct(
        private readonly OtlpMetricsMapper $mapper,
        private readonly MetricWriter $writer,
        private readonly OtlpMetricsProtobufDecoder $protobuf,
    ) {}

    protected function decodeProtobuf(string $body): array
    {
        return $this->protobuf->decodeMetrics($body);
    }

    protected function mapExport(mixed $payload, string $projectId): MappedMetrics
    {
        return $this->mapper->map($payload, $projectId);
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $rows
     */
    protected function writeRows(array $rows): void
    {
        $this->writer->write($rows);
    }

    protected function signal(): array
    {
        return [
            'rejectedField' => 'rejectedDataPoints',
            'rejectedMessage' => 'Some data points could not be stored and were skipped.',
            'logMessage' => 'Failed to write OTLP metrics to ClickHouse.',
            'unavailableMessage' => 'Metric storage is temporarily unavailable. Please retry.',
        ];
    }
}
