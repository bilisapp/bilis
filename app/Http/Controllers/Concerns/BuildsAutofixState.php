<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\Team;
use App\Models\TeamLlmCredential;
use Illuminate\Database\Eloquent\Collection;

/**
 * The autofix state a page hands its "Fix this" affordances.
 *
 * Shared by the log viewer and the issue page, which offer the same action on
 * the same kind of row; the browser turns it into a per-row target with
 * `autofixTargetFor()` in `resources/js/lib/logRow.ts`.
 */
trait BuildsAutofixState
{
    /**
     * Which service of which project has a codebase behind it.
     *
     * The viewer shows lines from every project the team owns, and any one of
     * them may or may not have a repository responsible for its service. That
     * is a settings fact, not something a browser may assert, so the mapping
     * is resolved here and the row only reads it: the "Fix this" affordance is
     * offered exactly where the endpoint would accept it, and elsewhere the
     * same button explains what connecting a repository would buy.
     *
     * Every team project is listed, connected or not, because the offer made
     * to an unconnected one needs its slug to point at its settings page.
     *
     * @param  Collection<int, Project>  $projects
     * @return array{enabled: bool, connected: bool, projects: array<string, array{slug: string, name: string, catchAll: string|null, services: array<string, string>}>, credentials: list<array<string, mixed>>}
     */
    private function autofixState(Team $team, Collection $projects): array
    {
        $enabled = config('autofix.enabled') === true;

        /** @var Collection<int, ProjectRepository> $repositories */
        $repositories = $enabled && $projects->isNotEmpty()
            ? ProjectRepository::query()
                ->whereIn('project_id', $projects->modelKeys())
                ->where('autofix_enabled', true)
                ->with('services')
                ->get()
            : new Collection;

        $map = [];

        foreach ($projects as $project) {
            $map[(string) $project->getKey()] = $this->autofixProjectState($project, $repositories);
        }

        return [
            'enabled' => $enabled,
            'connected' => $repositories->isNotEmpty(),
            'projects' => $map,
            /*
             * The keys the run dialog may choose between — never the keys
             * themselves, only the summary the settings page gets.
             */
            'credentials' => $enabled
                ? array_values($team->llmCredentials()
                    ->get()
                    ->map(fn (TeamLlmCredential $credential): array => $credential->toSummary())
                    ->all())
                : [],
        ];
    }

    /**
     * One project's service-to-repository claims, as the viewer reads them.
     *
     * @param  Collection<int, ProjectRepository>  $repositories
     * @return array{slug: string, name: string, catchAll: string|null, services: array<string, string>}
     */
    private function autofixProjectState(Project $project, Collection $repositories): array
    {
        $catchAll = null;
        $services = [];

        foreach ($repositories->where('project_id', $project->getKey()) as $repository) {
            foreach ($repository->services as $service) {
                if ($service->isCatchAll()) {
                    $catchAll = $repository->repo_full_name;

                    continue;
                }

                $services[$service->service_name] = $repository->repo_full_name;
            }
        }

        return [
            'slug' => $project->slug,
            'name' => $project->name,
            'catchAll' => $catchAll,
            'services' => $services,
        ];
    }
}
