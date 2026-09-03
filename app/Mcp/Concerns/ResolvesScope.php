<?php

namespace App\Mcp\Concerns;

use App\Mcp\Support\Scope;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Turn a tool call's `team`, `project` and `from`/`to` arguments into something
 * a query service will accept.
 *
 * Two rules live here so no tool has to remember them. A team is only ever one
 * the authenticated user belongs to, and an unknown slug is answered the same
 * way whether it exists for someone else or not at all. And a project slug is
 * mapped to the numeric ClickHouse `ProjectId` here — a slug never reaches SQL.
 */
trait ResolvesScope
{
    /**
     * Resolve the team, projects and project ids a call may read.
     *
     * On any failure an MCP error response is returned instead of a scope, and
     * the caller must return it directly.
     */
    protected function resolveScope(Request $request): Scope|Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return Response::error('You are not signed in. Reconnect to Bilis and approve the sign-in.');
        }

        $slug = $request->get('team');

        if (is_string($slug) && $slug !== '') {
            $team = $user->teams()->where('slug', $slug)->first();

            if ($team === null) {
                return Response::error(
                    "You are not a member of a team with slug '{$slug}'. Call list-teams to see your teams."
                );
            }
        } else {
            $team = $user->currentTeam ?? $user->fallbackTeam();
        }

        if (! $team instanceof Team) {
            return Response::error('You do not belong to any team yet. Create one in the Bilis web app first.');
        }

        $projects = $team->projects()->orderBy('name')->get();
        $project = $request->get('project');

        if (is_string($project) && $project !== '') {
            $matching = $projects->where('slug', $project);

            if ($matching->isEmpty()) {
                return Response::error(
                    "Team '{$team->slug}' has no project with slug '{$project}'. Call list-projects to see its projects."
                );
            }

            return new Scope($team, $projects, $this->idsOf($matching->all()));
        }

        return new Scope($team, $projects, $this->idsOf($projects->all()));
    }

    /**
     * The time window a call reads, defaulting to the last hour.
     *
     * Windows are always bounded: every ClickHouse read in Bilis is written
     * against the sort key's `Timestamp` range (R4), and an unbounded one would
     * scan the whole partition set.
     *
     * @return array{Carbon, Carbon}
     */
    protected function window(Request $request, int $defaultMinutes = 60): array
    {
        $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $to = is_string($value = $request->get('to')) && $value !== ''
            ? Carbon::parse($value)->utc()
            : Carbon::now('UTC');

        $from = is_string($value = $request->get('from')) && $value !== ''
            ? Carbon::parse($value)->utc()
            : $to->copy()->subMinutes($defaultMinutes);

        return $from->greaterThan($to) ? [$to, $from] : [$from, $to];
    }

    /**
     * Read a non-empty string argument, or null when it was not supplied.
     */
    protected function argument(Request $request, string $key): ?string
    {
        $value = $request->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Cast projects to the `String` ids the ClickHouse tables are keyed by.
     *
     * @param  array<int, Project>  $projects
     * @return list<string>
     */
    private function idsOf(array $projects): array
    {
        return array_values(array_map(fn (Project $project): string => (string) $project->id, $projects));
    }
}
