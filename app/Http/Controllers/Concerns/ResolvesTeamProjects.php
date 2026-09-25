<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Project;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * The team a page is scoped to, its projects, and the ClickHouse project ids a
 * query may read.
 *
 * The ProjectId boundary for every in-app read: ids come from the current
 * team's own projects, narrowed by slug here, cast to strings because
 * `ProjectId` is a String column — and a slug never reaches SQL. The log and
 * trace controllers carry older copies of these three methods; new pages use
 * this.
 */
trait ResolvesTeamProjects
{
    /**
     * Resolve the team the request is scoped to.
     */
    private function team(Request $request): Team
    {
        $team = $request->route('current_team');

        if ($team instanceof Team) {
            return $team;
        }

        if (is_string($team)) {
            return Team::where('slug', $team)->firstOrFail();
        }

        abort(403);
    }

    /**
     * The projects the team owns, ordered by name.
     *
     * @return Collection<int, Project>
     */
    private function projects(Team $team): Collection
    {
        return $team->projects()->orderBy('name')->get();
    }

    /**
     * The project ids a query may read, optionally narrowed to one slug.
     *
     * @param  Collection<int, Project>  $projects
     * @return list<string>
     */
    private function projectIds(Collection $projects, ?string $slug): array
    {
        if ($slug !== null) {
            $projects = $projects->where('slug', $slug);
        }

        return array_values($projects->map(fn (Project $project): string => (string) $project->id)->all());
    }
}
