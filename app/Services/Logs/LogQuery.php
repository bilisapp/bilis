<?php

namespace App\Services\Logs;

use App\Services\ClickHouse\ClickHouseClient;
use App\Services\ClickHouse\ClickHouseException;
use Illuminate\Support\Carbon;

/**
 * Builds and runs the parameterized ClickHouse statements behind the log viewer.
 *
 * Every statement is constrained to an explicit list of project ids resolved on
 * the server, and every user supplied value is bound as a ClickHouse query
 * parameter rather than being interpolated into the SQL.
 *
 * @phpstan-type LogRow array{timestamp: string, traceId: string, spanId: string, severityText: string, severityNumber: int, serviceName: string, body: string, scopeName: string, scopeVersion: string, resourceAttributes: array<string, string>, logAttributes: array<string, string>, projectId: int}
 * @phpstan-type LogResult array{rows: list<LogRow>, nextCursor: string|null, unavailable: bool}
 */
class LogQuery
{
    /**
     * The columns projected by every log statement.
     */
    private const COLUMNS = 'ProjectId, Timestamp, TraceId, SpanId, SeverityText, SeverityNumber, ServiceName, Body, ScopeName, ScopeVersion, ResourceAttributes, LogAttributes';

    /**
     * A search term made only of these characters can use the token bloom filter.
     */
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_]{3,}$/';

    public function __construct(private readonly ClickHouseClient $client) {}

    /**
     * Fetch a page of logs, newest first.
     *
     * @param  list<int>  $projectIds
     * @return LogResult
     */
    public function search(array $projectIds, LogFilters $filters): array
    {
        if ($projectIds === []) {
            return ['rows' => [], 'nextCursor' => null, 'unavailable' => false];
        }

        [$conditions, $params] = $this->conditions($projectIds, $filters);

        if ($filters->cursor !== null) {
            $conditions[] = 'Timestamp < {cursor:DateTime64(9)}';
            $params['cursor'] = $filters->cursor;
        }

        $params['rowLimit'] = $filters->limit;

        $rows = $this->run($conditions, $params);

        if ($rows === null) {
            return ['rows' => [], 'nextCursor' => null, 'unavailable' => true];
        }

        $nextCursor = count($rows) < $filters->limit
            ? null
            : ($rows[count($rows) - 1]['timestamp'] ?? null);

        return ['rows' => $rows, 'nextCursor' => $nextCursor, 'unavailable' => false];
    }

    /**
     * Fetch the logs recorded after the given timestamp, newest first.
     *
     * @param  list<int>  $projectIds
     * @return LogResult
     */
    public function tail(array $projectIds, LogFilters $filters, ?string $after): array
    {
        if ($projectIds === []) {
            return ['rows' => [], 'nextCursor' => null, 'unavailable' => false];
        }

        [$conditions, $params] = $this->conditions($projectIds, $filters, withTimeWindow: false);

        $conditions[] = 'Timestamp > {after:DateTime64(9)}';
        $params['after'] = $after ?? $filters->from->clone()->utc()->format('Y-m-d H:i:s.u');
        $params['rowLimit'] = $filters->limit;

        $rows = $this->run($conditions, $params);

        if ($rows === null) {
            return ['rows' => [], 'nextCursor' => null, 'unavailable' => true];
        }

        return ['rows' => $rows, 'nextCursor' => null, 'unavailable' => false];
    }

    /**
     * Run the statement, returning null when ClickHouse is temporarily unavailable.
     *
     * @param  list<string>  $conditions
     * @param  array<string, scalar|null>  $params
     * @return list<LogRow>|null
     */
    private function run(array $conditions, array $params): ?array
    {
        $sql = sprintf(
            'SELECT %s FROM otel_logs WHERE %s ORDER BY Timestamp DESC LIMIT {rowLimit:UInt32}',
            self::COLUMNS,
            implode(' AND ', $conditions),
        );

        try {
            $rows = $this->client->select($sql, $params);
        } catch (ClickHouseException $exception) {
            if (! $exception->isOverload()) {
                throw $exception;
            }

            report($exception);

            return null;
        }

        return array_values(array_map($this->mapRow(...), $rows));
    }

    /**
     * Build the shared WHERE conditions and their bound parameters.
     *
     * @param  list<int>  $projectIds
     * @return array{0: list<string>, 1: array<string, scalar|null>}
     */
    private function conditions(array $projectIds, LogFilters $filters, bool $withTimeWindow = true): array
    {
        $conditions = ['ProjectId IN {projectIds:Array(UInt64)}'];
        $params = ['projectIds' => '['.implode(',', $projectIds).']'];

        if ($withTimeWindow) {
            $conditions[] = 'Timestamp >= {from:DateTime64(9)}';
            $conditions[] = 'Timestamp <= {to:DateTime64(9)}';
            $params['from'] = $this->formatTimestamp($filters->from);
            $params['to'] = $this->formatTimestamp($filters->to);
        }

        if ($filters->service !== null) {
            $conditions[] = 'ServiceName = {service:String}';
            $params['service'] = $filters->service;
        }

        if ($filters->severities !== []) {
            $ranges = [];

            foreach ($filters->severities as $index => $level) {
                $ranges[] = sprintf(
                    '(SeverityNumber >= {severityMin%d:UInt8} AND SeverityNumber <= {severityMax%d:UInt8})',
                    $index,
                    $index,
                );

                $params['severityMin'.$index] = $level->minimumSeverityNumber();
                $params['severityMax'.$index] = $level->maximumSeverityNumber();
            }

            $conditions[] = '('.implode(' OR ', $ranges).')';
        }

        if ($filters->search !== null) {
            if (preg_match(self::TOKEN_PATTERN, $filters->search) === 1) {
                $conditions[] = 'hasToken(Body, {search:String})';
                $params['search'] = $filters->search;
            } else {
                $conditions[] = 'Body ILIKE {search:String}';
                $params['search'] = '%'.$this->escapeLike($filters->search).'%';
            }
        }

        return [$conditions, $params];
    }

    /**
     * Render a timestamp the way ClickHouse expects a DateTime64 parameter.
     */
    private function formatTimestamp(Carbon $timestamp): string
    {
        return $timestamp->clone()->utc()->format('Y-m-d H:i:s.u');
    }

    /**
     * Escape the ILIKE wildcards so a search term is matched literally.
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }

    /**
     * Normalise a ClickHouse row into the shape the frontend consumes.
     *
     * @param  array<string, mixed>  $row
     * @return LogRow
     */
    private function mapRow(array $row): array
    {
        /** @var array<string, string> $resourceAttributes */
        $resourceAttributes = is_array($row['ResourceAttributes'] ?? null) ? $row['ResourceAttributes'] : [];
        /** @var array<string, string> $logAttributes */
        $logAttributes = is_array($row['LogAttributes'] ?? null) ? $row['LogAttributes'] : [];

        return [
            'projectId' => (int) ($row['ProjectId'] ?? 0),
            'timestamp' => (string) ($row['Timestamp'] ?? ''),
            'traceId' => (string) ($row['TraceId'] ?? ''),
            'spanId' => (string) ($row['SpanId'] ?? ''),
            'severityText' => (string) ($row['SeverityText'] ?? ''),
            'severityNumber' => (int) ($row['SeverityNumber'] ?? 0),
            'serviceName' => (string) ($row['ServiceName'] ?? ''),
            'body' => (string) ($row['Body'] ?? ''),
            'scopeName' => (string) ($row['ScopeName'] ?? ''),
            'scopeVersion' => (string) ($row['ScopeVersion'] ?? ''),
            'resourceAttributes' => $resourceAttributes,
            'logAttributes' => $logAttributes,
        ];
    }
}
