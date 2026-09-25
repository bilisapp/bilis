<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesOtlpExport;
use App\Http\Controllers\Controller;
use App\Services\Ingest\LogWriter;
use App\Services\Ingest\MappedLogs;
use App\Services\Ingest\OtlpLogMapper;
use App\Services\Ingest\Protobuf\OtlpProtobufDecoder;

/**
 * The OTLP/HTTP logs endpoint, in both the JSON and protobuf encodings.
 *
 * Ingestion is deliberately forgiving: records that cannot be mapped are
 * reported through OTLP's partial success response instead of failing the
 * whole export, so a misbehaving client never loses its healthy records. The
 * request handling shared with traces and metrics is {@see HandlesOtlpExport}.
 */
class OtlpLogController extends Controller
{
    use HandlesOtlpExport;

    public function __construct(
        private readonly OtlpLogMapper $mapper,
        private readonly LogWriter $writer,
        private readonly OtlpProtobufDecoder $protobuf,
    ) {}

    protected function decodeProtobuf(string $body): array
    {
        return $this->protobuf->decodeLogs($body);
    }

    protected function mapExport(mixed $payload, string $projectId): MappedLogs
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
            'rejectedField' => 'rejectedLogRecords',
            'rejectedMessage' => 'Some log records could not be parsed and were skipped.',
            'logMessage' => 'Failed to write OTLP log records to ClickHouse.',
            'unavailableMessage' => 'Log storage is temporarily unavailable. Please retry.',
        ];
    }
}
