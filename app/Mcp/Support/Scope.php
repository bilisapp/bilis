<?php

namespace App\Mcp\Support;

use App\Models\Project;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;

/**
 * The team, projects and ClickHouse project ids one MCP tool call may read.
 *
 * Resolved once per call by `App\Mcp\Concerns\ResolvesScope`, so no tool ever
 * builds a project id list of its own — and a project *slug* never travels any
 * further than this class.
 */
final readonly class Scope
{
    /**
     * @param  Collection<int, Project>  $projects  every project in the team
     * @param  list<string>  $projectIds  the ids the query may read, narrowed to one project when the call named one
     */
    public function __construct(
        public Team $team,
        public Collection $projects,
        public array $projectIds,
    ) {}

    /**
     * The project ids of the whole team, ignoring any project argument.
     *
     * @return list<string>
     */
    public function allProjectIds(): array
    {
        return array_values($this->projects->map(fn (Project $project): string => (string) $project->id)->all());
    }
}
