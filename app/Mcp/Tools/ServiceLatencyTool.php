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

#[Name('service-latency')]
#[Title('Service latency')]
#[Description('p95 and p99 span duration, span count and error rate per service over a time window — the busiest twenty services, slowest first. This answers "what is slow?" in one call; reach for list-traces afterwards to open an individual offender.')]
class ServiceLatencyTool extends Tool
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
        ]);

        [$from, $to] = $this->window($request);

        $result = $traces->serviceLatency($scope->projectIds, new TraceFilters(
            service: $this->argument($request, 'service'),
            from: $from,
            to: $to,
        ));

        return Response::json([
            'team' => $scope->team->slug,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'unavailable' => $result['unavailable'],
            'services' => $result['rows'],
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
            'service' => $schema->string()->description('Narrow to one service instead of the busiest twenty.'),
            'from' => $schema->string()->description('Start of the window, ISO-8601. Defaults to an hour before "to".'),
            'to' => $schema->string()->description('End of the window, ISO-8601. Defaults to now.'),
        ];
    }
}
