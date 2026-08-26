<?php

namespace App\Services\ClickHouse;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

/**
 * A small typed client for the ClickHouse HTTP interface.
 *
 * User supplied values are never interpolated into SQL. Instead they are sent
 * as ClickHouse server side query parameters and referenced from the statement
 * using `{name:Type}` placeholders.
 */
class ClickHouseClient
{
    /**
     * Table and database identifiers must be plain, optionally qualified names.
     */
    private const IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/';

    public function __construct(private readonly Repository $config) {}

    /**
     * Run a read query and return the decoded rows.
     *
     * @param  array<string, scalar|null>  $params
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        $response = $this->send($sql, [
            'default_format' => 'JSONEachRow',
            ...$this->queryParameters($params),
        ], $sql);

        return $this->decodeRows($response);
    }

    /**
     * Insert rows into the given table using asynchronous inserts.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function insert(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->guardIdentifier($table);

        $statement = sprintf('INSERT INTO %s FORMAT JSONEachRow', $table);

        $this->send($this->encodeRows($rows), [
            'query' => $statement,
            'async_insert' => 1,
            'wait_for_async_insert' => 0,
        ], $statement);
    }

    /**
     * Run a statement that returns no rows, such as DDL.
     *
     * When `$withDatabase` is false the configured database is not attached to
     * the request, which is required for statements such as CREATE DATABASE.
     */
    public function execute(string $sql, bool $withDatabase = true): void
    {
        $this->send($sql, [], $sql, $withDatabase);
    }

    /**
     * The configured database name.
     */
    public function database(): string
    {
        $database = (string) $this->config->get('clickhouse.database', 'bilis');

        $this->guardIdentifier($database);

        return $database;
    }

    /**
     * Send a request to the ClickHouse HTTP interface.
     *
     * @param  array<string, scalar>  $query
     */
    private function send(string $body, array $query, string $statement, bool $withDatabase = true): Response
    {
        if ($withDatabase) {
            $query = ['database' => $this->database(), ...$query];
        }

        try {
            $response = $this->request()
                ->withQueryParameters($query)
                ->withBody($body, 'text/plain')
                ->post($this->baseUrl());
        } catch (ConnectionException $exception) {
            throw ClickHouseException::fromConnectionException($exception, $statement);
        }

        if ($response->failed()) {
            throw ClickHouseException::fromResponse($response, $statement);
        }

        return $response;
    }

    /**
     * Build the pending request with credentials and timeouts applied.
     */
    private function request(): PendingRequest
    {
        return Http::withHeaders([
            'X-ClickHouse-User' => (string) $this->config->get('clickhouse.username', 'default'),
            'X-ClickHouse-Key' => (string) $this->config->get('clickhouse.password', ''),
        ])
            ->timeout((int) $this->config->get('clickhouse.timeout', 10))
            ->connectTimeout((int) $this->config->get('clickhouse.connect_timeout', 3));
    }

    private function baseUrl(): string
    {
        return sprintf(
            '%s://%s:%d/',
            (string) $this->config->get('clickhouse.scheme', 'http'),
            (string) $this->config->get('clickhouse.host', '127.0.0.1'),
            (int) $this->config->get('clickhouse.port', 8123),
        );
    }

    /**
     * Translate bound values into ClickHouse `param_<name>` query parameters.
     *
     * @param  array<string, scalar|null>  $params
     * @return array<string, string>
     */
    private function queryParameters(array $params): array
    {
        $parameters = [];

        foreach ($params as $name => $value) {
            $this->guardParameterName($name);

            $parameters['param_'.$name] = match (true) {
                is_bool($value) => $value ? '1' : '0',
                $value === null => '\N',
                default => (string) $value,
            };
        }

        return $parameters;
    }

    /**
     * Encode rows as newline delimited JSON.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function encodeRows(array $rows): string
    {
        $lines = [];

        foreach ($rows as $row) {
            try {
                $lines[] = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (JsonException $exception) {
                throw ClickHouseException::fromInvalidResponse(
                    'Unable to encode row for ClickHouse insert: '.$exception->getMessage(),
                    $exception,
                );
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Decode a JSONEachRow response body.
     *
     * @return array<int, array<string, mixed>>
     */
    private function decodeRows(Response $response): array
    {
        $rows = [];

        foreach (explode("\n", trim($response->body())) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            try {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw ClickHouseException::fromInvalidResponse(
                    'Unable to decode ClickHouse response: '.$exception->getMessage(),
                    $exception,
                );
            }

            $rows[] = $decoded;
        }

        return $rows;
    }

    private function guardIdentifier(string $identifier): void
    {
        if (preg_match(self::IDENTIFIER_PATTERN, $identifier) !== 1) {
            throw ClickHouseException::fromInvalidResponse(
                sprintf('Invalid ClickHouse identifier [%s].', $identifier),
            );
        }
    }

    private function guardParameterName(string $name): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw ClickHouseException::fromInvalidResponse(
                sprintf('Invalid ClickHouse query parameter name [%s].', $name),
            );
        }
    }
}
