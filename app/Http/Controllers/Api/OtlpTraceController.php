<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesOtlpExport;
use App\Http\Controllers\Controller;
use App\Services\Ingest\MappedSpans;
use App\Services\Ingest\OtlpTraceMapper;
use App\Services\Ingest\Protobuf\OtlpProtobufDecoder;
use App\Services\Ingest\SpanWriter;

/**
 * The OTLP/HTTP traces endpoint, in both the JSON and protobuf encodings.
 *
 * The twin of {@see OtlpLogController}, and deliberately forgiving in the same
 * way: spans that cannot be mapped are reported through OTLP's partial success
 * response rather than failing the whole export, so one malformed span never
 * costs a client the batch around it.
 *
 * OTLP over gRPC is not supported. Collectors default to gRPC on port 4317, so
 * this is the single most likely reason a new user thinks Bilis is broken — it
 * is documented at `resources/docs/ingestion/traces.md` rather than left to be
 * discovered.
 */
class OtlpTraceController extends Controller
{
    use HandlesOtlpExport;

    public function __construct(
        private readonly OtlpTraceMapper $mapper,
        private readonly SpanWriter $writer,
        private readonly OtlpProtobufDecoder $protobuf,
    ) {}

    protected function decodeProtobuf(string $body): array
    {
        return $this->protobuf->decodeTraces($body);
    }

    protected function mapExport(mixed $payload, string $projectId): MappedSpans
    {
        return $this->mapper->map($payload, $projectId);
    }

    protected function writeRows(array $rows): void
    {
        $this->writer->write($rows);
    }

    protected function signal(): array
    {
        return [
            'rejectedField' => 'rejectedSpans',
            'rejectedMessage' => 'Some spans could not be parsed and were skipped.',
            'logMessage' => 'Failed to write OTLP spans to ClickHouse.',
            'unavailableMessage' => 'Trace storage is temporarily unavailable. Please retry.',
        ];
    }
}
