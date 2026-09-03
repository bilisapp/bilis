<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesScope;
use App\Services\Traces\TraceFilters;
use App\Services\Traces\TraceQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Name('list-traces')]
#[Title('List traces')]
#[Description('List traces (whole requests) over a time window, newest first, with their root operation, duration, span count and error count. Filter to failing traces or to ones slower than a threshold to find something worth opening. Pass a traceId from here to get-trace for the waterfall. A trace whose spansExpired is true is older than the 30-day span retention and has no waterfall left.')]
class ListTracesTool extends Tool
{
    use ResolvesScope;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, TraceQuery $traces): Response
    {
        $scope = $this->resolveScope($request);

        if ($scope instanceof Response) {
            return $scope;
        }

        $request->validate([
            'service' => ['sometimes', 'string', 'max:255'],
            'errors_only' => ['sometimes', 'boolean'],
            'min_duration_ms' => ['sometimes', 'integer', 'min:0', 'max:3600000'],
            'cursor' => ['sometimes', 'string', 'max:64'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.TraceFilters::LIMIT],
        ]);

        [$from, $to] = $this->window($request);

        $minDuration = $request->get('min_duration_ms');

        $filters = new TraceFilters(
            service: $this->argument($request, 'service'),
            errorsOnly: (bool) $request->get('errors_only', false),
            minDurationMs: is_numeric($minDuration) ? (int) $minDuration : null,
            from: $from,
            to: $to,
            cursor: $this->argument($request, 'cursor'),
            limit: (int) ($request->get('limit') ?? 25),
        );

        $result = $traces->list($scope->projectIds, $filters);

        return Response::json([
            'team' => $scope->team->slug,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'count' => count($result['rows']),
            'nextCursor' => $result['nextCursor'],
            'unavailable' => $result['unavailable'],
            'traces' => $result['rows'],
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
            'team' => $schema->string()->description('Team slug. Omit to use the current team.'),
            'project' => $schema->string()->description('Project slug. Omit to read every project in the team.'),
            'service' => $schema->string()->description('Only traces whose root span belongs to this service.'),
            'errors_only' => $schema->boolean()->description('Only traces containing at least one failed span.'),
            'min_duration_ms' => $schema->integer()->description('Only traces at least this many milliseconds long.'),
            'from' => $schema->string()->description('Start of the window, ISO-8601. Defaults to an hour before "to".'),
            'to' => $schema->string()->description('End of the window, ISO-8601. Defaults to now.'),
            'cursor' => $schema->string()->description('The nextCursor from a previous call, for the next page.'),
            'limit' => $schema->integer()->description('Traces to return, 1–'.TraceFilters::LIMIT.'. Defaults to 25.'),
        ];
    }
}
