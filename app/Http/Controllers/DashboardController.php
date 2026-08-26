<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Services\Logs\LogOnboarding;
use App\Services\Logs\LogStorage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, LogOnboarding $onboarding, LogStorage $storage): Response
    {
        $email = strtolower($request->user()->email);

        $pendingInvitations = TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation) => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviter->name,
                'team' => [
                    'name' => $invitation->team->name,
                    'slug' => $invitation->team->slug,
                ],
            ]);

        $team = $this->team($request);
        $projects = $team->projects()->orderBy('name')->get();
        $firstProject = $projects->first();
        $projectIds = array_values($projects->map(fn (Project $project): string => (string) $project->id)->all());

        return Inertia::render('Dashboard', [
            'pendingInvitations' => $pendingInvitations,
            'onboarding' => $onboarding->state($team, $projectIds),
            'storage' => $this->storage($storage, $projects, $projectIds),
            'firstProject' => $firstProject instanceof Project
                ? ['name' => $firstProject->name, 'slug' => $firstProject->slug]
                : null,
        ]);
    }

    /**
     * The storage card's prop: retained bytes per project, largest first.
     *
     * Null when the team has no projects — the card has nothing to say until
     * onboarding is past its first step.
     *
     * @param  Collection<int, Project>  $projects
     * @param  list<string>  $projectIds
     * @return array{totalBytes: int, unavailable: bool, projects: list<array{name: string, slug: string, rows: int, bytes: int}>}|null
     */
    private function storage(LogStorage $storage, $projects, array $projectIds): ?array
    {
        if ($projectIds === []) {
            return null;
        }

        $usage = $storage->usage($projectIds);
        $byId = $projects->keyBy(fn (Project $project): string => (string) $project->id);

        $rows = [];

        foreach ($usage['projects'] as $entry) {
            $project = $byId->get($entry['projectId']);

            if (! $project instanceof Project) {
                continue;
            }

            $rows[] = [
                'name' => $project->name,
                'slug' => $project->slug,
                'rows' => $entry['rows'],
                'bytes' => $entry['bytes'],
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        return [
            'totalBytes' => $usage['totalBytes'],
            'unavailable' => $usage['unavailable'],
            'projects' => $rows,
        ];
    }

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
}
