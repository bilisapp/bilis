<?php

namespace App\Services\Logs;

use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Answers "how much disk are these projects using" for the dashboard.
 *
 * ClickHouse reports exact compressed bytes only per table (system.parts),
 * never per row, so the per-project figure is an estimate: each project's
 * uncompressed bytes, scaled by the table's own compression ratio. The table
 * is shared by every team on a hosted install, so its total is never shown
 * or apportioned — a team only ever sees numbers derived from its own rows,
 * and the ratio, which says nothing about how much anyone else stores.
 *
 * The scan is bounded by the server-resolved project id list — like
 * hasAnyLogs() it deliberately ignores every viewer filter and the time
 * window, because "how much disk" is a question about everything retained,
 * not about the fifteen minutes on screen.
 *
 * @phpstan-type ProjectStorage array{projectId: string, rows: int, bytes: int}
 * @phpstan-type StorageResult array{totalBytes: int, projects: list<ProjectStorage>, unavailable: bool}
 */
class LogStorage
{
    /**
     * How long a measured result is reused.
     *
     * The apportioning query reads every row's byte sizes, so it must not run
     * per page view. Storage moves slowly; a five minute old answer is right.
     */
    private const CACHE_SECONDS = 300;

    /**
     * Every column of otel_logs, so the uncompressed estimate counts whole rows.
     */
    private const BYTE_SIZE_COLUMNS = 'Timestamp, TraceId, SpanId, TraceFlags, SeverityText, SeverityNumber, ServiceName, Body, ResourceSchemaUrl, ResourceAttributes, ScopeSchemaUrl, ScopeName, ScopeVersion, ScopeAttributes, LogAttributes, EventName, ProjectId';

    public function __construct(
        private readonly ClickHouseClient $client,
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Measure retained storage for the given projects.
     *
     * An overloaded ClickHouse yields `unavailable: true` with zeroes — and is
     * never cached, so the card recovers on the next visit instead of showing
     * a stale outage for the full cache window.
     *
     * @param  list<string>  $projectIds
     * @return StorageResult
     */
    public function usage(array $projectIds): array
    {
        if ($projectIds === []) {
            return ['totalBytes' => 0, 'projects' => [], 'unavailable' => false];
        }

        $key = 'logs.storage.'.sha1(implode(',', $projectIds));

        /** @var StorageResult|null $cached */
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $result = $this->measure($projectIds);
        } catch (ClickHouseException $exception) {
            if (! $exception->isOverload()) {
                throw $exception;
            }

            report($exception);

            return ['totalBytes' => 0, 'projects' => [], 'unavailable' => true];
        }

        $this->cache->put($key, $result, self::CACHE_SECONDS);

        return $result;
    }

    /**
     * Run both measurements and scale each project's rows by the table's ratio.
     *
     * @param  list<string>  $projectIds
     * @return StorageResult
     */
    private function measure(array $projectIds): array
    {
        $ratio = $this->compressionRatio();

        $sql = sprintf(
            'SELECT ProjectId, count() AS Rows, sum(byteSize(%s)) AS Bytes '
            .'FROM otel_logs WHERE ProjectId IN {projectIds:Array(String)} GROUP BY ProjectId',
            self::BYTE_SIZE_COLUMNS,
        );

        $rows = $this->client->select($sql, ['projectIds' => $this->projectIdsParameter($projectIds)]);

        $uncompressed = [];
        $rowCounts = [];

        foreach ($rows as $row) {
            $projectId = (string) ($row['ProjectId'] ?? '');

            if ($projectId === '') {
                continue;
            }

            $uncompressed[$projectId] = (int) ($row['Bytes'] ?? 0);
            $rowCounts[$projectId] = (int) ($row['Rows'] ?? 0);
        }

        $projects = [];
        $totalBytes = 0;

        foreach ($projectIds as $projectId) {
            $bytes = (int) round(($uncompressed[$projectId] ?? 0) * $ratio);
            $totalBytes += $bytes;

            $projects[] = [
                'projectId' => $projectId,
                'rows' => $rowCounts[$projectId] ?? 0,
                'bytes' => $bytes,
            ];
        }

        return ['totalBytes' => $totalBytes, 'projects' => $projects, 'unavailable' => false];
    }

    /**
     * Bytes on disk per uncompressed byte, across the whole logs table.
     *
     * A ratio, never the table's size: the table holds every team's rows.
     * An empty table has no ratio to speak of, so it answers 1.0 (no
     * compression claimed) rather than zero.
     */
    private function compressionRatio(): float
    {
        $sql = 'SELECT sum(bytes_on_disk) AS Compressed, sum(data_uncompressed_bytes) AS Uncompressed FROM system.parts '
            .'WHERE database = {db:String} AND table = {table:String} AND active';

        $rows = $this->client->select($sql, [
            'db' => $this->client->database(),
            'table' => 'otel_logs',
        ]);

        $compressed = (int) ($rows[0]['Compressed'] ?? 0);
        $uncompressed = (int) ($rows[0]['Uncompressed'] ?? 0);

        return $compressed > 0 && $uncompressed > 0 ? $compressed / $uncompressed : 1.0;
    }

    /**
     * Render a project id list the way ClickHouse expects an Array(String) parameter.
     *
     * @param  list<string>  $projectIds
     */
    private function projectIdsParameter(array $projectIds): string
    {
        $quoted = array_map(
            fn (string $id): string => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $id)."'",
            $projectIds,
        );

        return '['.implode(',', $quoted).']';
    }
}
