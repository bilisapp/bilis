<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesScope;
use App\Services\Logs\LogFilters;
use App\Services\Logs\LogQuery;
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

#[Name('list-services')]
#[Title('List services')]
#[Description('List the service names a project has actually been sending, split into the ones that log and the ones that trace. Call this before filtering by service: a guessed name silently matches nothing.')]
class ListServicesTool extends Tool
{
    use ResolvesScope;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, LogQuery $logs, TraceQuery $traces): Response
    {
        $scope = $this->resolveScope($request);

        if ($scope instanceof Response) {
            return $scope;
        }

        [$from, $to] = $this->window($request);

        return Response::json([
            'team' => $scope->team->slug,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'logging' => $logs->services($scope->projectIds, new LogFilters(from: $from, to: $to)),
            'tracing' => $traces->services($scope->projectIds, new TraceFilters(from: $from, to: $to)),
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
            'from' => $schema->string()->description('Start of the window, ISO-8601. Defaults to an hour before "to".'),
            'to' => $schema->string()->description('End of the window, ISO-8601. Defaults to now.'),
        ];
    }
}
