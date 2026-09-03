<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesScope;
use App\Models\Project;
use App\Services\Logs\LogQuery;
use App\Services\Traces\TraceQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Name('list-projects')]
#[Title('List projects')]
#[Description('List a team\'s projects. A project is one application\'s logs and traces, and its slug is what every other tool takes as its "project" argument. Each project reports whether it has ever received logs or spans, so an empty answer from a search can be told apart from an app that was never wired up.')]
class ListProjectsTool extends Tool
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

        return Response::json([
            'team' => $scope->team->slug,
            'projects' => $scope->projects
                ->map(function (Project $project) use ($logs, $traces): array {
                    $ids = [(string) $project->id];

                    return [
                        'slug' => $project->slug,
                        'name' => $project->name,
                        'hasLogs' => $logs->hasAnyLogs($ids),
                        'hasTraces' => $traces->hasAnyTraces($ids),
                    ];
                })
                ->values(),
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
                ->description('Team slug. Omit to use the current team. Call list-teams to discover slugs.'),
        ];
    }
}
