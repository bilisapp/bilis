<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesScope;
use App\Services\Logs\LogFilters;
use App\Services\Logs\LogQuery;
use App\Services\Logs\SeverityLevel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Name('search-logs')]
#[Title('Search logs')]
#[Description('Search a project\'s log lines over a time window, newest first. Filter by service, severity, a full-text term matched against the message body, or the trace or span a line belongs to. Every row carries the traceId of the request it happened inside — pass that to get-trace to see the whole request. Defaults to the last hour of every project in the team.')]
class SearchLogsTool extends Tool
{
    use ResolvesScope;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, LogQuery $logs): Response
    {
        $scope = $this->resolveScope($request);

        if ($scope instanceof Response) {
            return $scope;
        }

        $request->validate([
            'service' => ['sometimes', 'string', 'max:255'],
            'severity' => ['sometimes', 'array'],
            'severity.*' => ['string', 'in:'.implode(',', SeverityLevel::values())],
            'search' => ['sometimes', 'string', 'max:255'],
            'trace_id' => ['sometimes', 'string', 'regex:/^[0-9a-fA-F]{32}$/'],
            'span_id' => ['sometimes', 'string', 'regex:/^[0-9a-fA-F]{16}$/'],
            'cursor' => ['sometimes', 'string', 'max:64'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.LogFilters::LIMIT],
        ], [
            'trace_id.regex' => 'A trace id is 32 hexadecimal characters.',
            'span_id.regex' => 'A span id is 16 hexadecimal characters.',
        ]);

        [$from, $to] = $this->window($request);

        $severities = array_values(array_filter(array_map(
            fn (mixed $value): ?SeverityLevel => is_string($value) ? SeverityLevel::tryFrom($value) : null,
            is_array($raw = $request->get('severity', [])) ? $raw : [],
        )));

        $filters = new LogFilters(
            service: $this->argument($request, 'service'),
            severities: $severities,
            search: $this->argument($request, 'search'),
            traceId: ($trace = $this->argument($request, 'trace_id')) === null ? null : strtolower($trace),
            spanId: ($span = $this->argument($request, 'span_id')) === null ? null : strtolower($span),
            from: $from,
            to: $to,
            cursor: $this->argument($request, 'cursor'),
            limit: (int) ($request->get('limit') ?? 50),
        );

        $result = $logs->search($scope->projectIds, $filters);

        return Response::json([
            'team' => $scope->team->slug,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'count' => count($result['rows']),
            'nextCursor' => $result['nextCursor'],
            'unavailable' => $result['unavailable'],
            'rows' => array_map(fn (array $row): array => [
                'timestamp' => $row['timestamp'],
                'severity' => $row['severityText'],
                'service' => $row['serviceName'],
                'body' => $row['body'],
                'traceId' => $row['traceId'],
                'spanId' => $row['spanId'],
                'attributes' => $row['logAttributes'],
            ], $result['rows']),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'team' => $schema->string()
                ->description('Team slug. Omit to use the current team.'),
            'project' => $schema->string()
                ->description('Project slug. Omit to read every project in the team. Call list-projects for slugs.'),
            'service' => $schema->string()
                ->description('Read one service only. Call list-services for the names this project actually uses.'),
            'severity' => $schema->array()
                ->items($schema->string()->enum(SeverityLevel::values()))
                ->description('Severities to include, e.g. ["error","fatal"]. Omit for every severity.'),
            'search' => $schema->string()
                ->description('Full-text term matched case-insensitively against the message body.'),
            'trace_id' => $schema->string()
                ->description('Only lines emitted inside this trace (32 hex characters).'),
            'span_id' => $schema->string()
                ->description('Only lines emitted inside this span (16 hex characters).'),
            'from' => $schema->string()
                ->description('Start of the window, ISO-8601. Defaults to an hour before "to".'),
            'to' => $schema->string()
                ->description('End of the window, ISO-8601. Defaults to now.'),
            'cursor' => $schema->string()
                ->description('The nextCursor from a previous call, to read the page of older lines.'),
            'limit' => $schema->integer()
                ->description('Rows to return, 1–'.LogFilters::LIMIT.'. Defaults to 50.'),
        ];
    }
}
