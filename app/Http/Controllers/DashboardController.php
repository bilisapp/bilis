<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Services\Ingest\IngestRateUsage;
use App\Services\Logs\LogDigest;
use App\Services\Logs\LogOnboarding;
use App\Services\Logs\LogStorage;
use App\Services\Plans\PlanUsage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, LogOnboarding $onboarding, LogStorage $storage, LogDigest $digest, IngestRateUsage $ingestRate, PlanUsage $planUsage): Response
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
        $projectIds = array_values($projects->map(fn (Project $project): string => (string) $project->id)->all());

        return Inertia::render('Dashboard', [
            'pendingInvitations' => $pendingInvitations,
            'onboarding' => $onboarding->state($team, $projectIds),
            'storage' => $this->storage($storage, $projects, $projectIds),
            'digest' => $this->digest($digest, $projectIds),
            'ingestRate' => $this->ingestRate($ingestRate, $projects),
            /*
             * Always present, unlike the storage and digest cards: where a
             * team stands on the Free plan is answerable before a single line
             * has arrived, and 0 of 3 projects is exactly what a new team
             * needs to read.
             */
            'planUsage' => $planUsage->forTeam($team, $projectIds),
            /*
             * The onboarding panel's project picker needs the same name/slug
             * list the logs page hands it; when the team is ready the panel
             * is gone and the list is simply small enough not to matter.
             */
            'projects' => $projects
                ->map(fn (Project $project): array => [
                    'name' => $project->name,
                    'slug' => $project->slug,
                ])
                ->values(),
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
     * The system health digest: volume, errors, recurring failures, liveness,
     * and the dense hourly series the tiles sparkline.
     *
     * Null when the team has no projects — there is nothing to be healthy yet,
     * and the section stays out of the way of the onboarding steps.
     *
     * @param  list<string>  $projectIds
     * @return array{logs: array{current: int, previous: int, deltaPercent: int|null}, errors: array{current: int, previous: int, deltaPercent: int|null}, topErrors: list<array{body: string, total: int}>, services: list<array{name: string, lastSeen: string, quiet: bool, series: list<int>, errorSeries: list<int>}>, series: list<array{bucket: string, total: int, errors: int}>, generatedAt: string, unavailable: bool}|null
     */
    private function digest(LogDigest $digest, array $projectIds): ?array
    {
        if ($projectIds === []) {
            return null;
        }

        $overview = $digest->overview($projectIds);

        return [
            'logs' => [
                'current' => $overview['logs']['current'],
                'previous' => $overview['logs']['previous'],
                'deltaPercent' => $this->deltaPercent($overview['logs']['current'], $overview['logs']['previous']),
            ],
            'errors' => [
                'current' => $overview['errors']['current'],
                'previous' => $overview['errors']['previous'],
                'deltaPercent' => $this->deltaPercent($overview['errors']['current'], $overview['errors']['previous']),
            ],
            'topErrors' => $overview['topErrors'],
            'services' => $overview['services'],
            'series' => $overview['series'],
            'generatedAt' => $overview['generatedAt'],
            'unavailable' => $overview['unavailable'],
        ];
    }

    /**
     * The ingest throttle's live per-key counters for the current minute.
     *
     * Null when the team has no projects — there is no key to spend a budget
     * yet, so the card stays out of the onboarding steps' way. A team with
     * projects but no keys still gets the card, which then says so.
     *
     * The counters are the limiter's own rolling minute: ephemeral by design,
     * labelled "this minute", and never charted over time.
     *
     * @param  Collection<int, Project>  $projects
     * @return array{limit: int, disabled: bool, keys: list<array{project: string, projectSlug: string, name: string, keyPrefix: string, attempts: int, remaining: int}>}|null
     */
    private function ingestRate(IngestRateUsage $ingestRate, $projects): ?array
    {
        if ($projects->isEmpty()) {
            return null;
        }

        $apiKeys = ProjectApiKey::query()
            ->whereIn('project_id', $projects->modelKeys())
            ->with('project')
            ->orderBy('project_id')
            ->orderBy('name')
            ->get();

        return $ingestRate->forKeys($apiKeys);
    }

    /**
     * The change from the prior day as a whole percentage.
     *
     * Null when there is no prior day to compare against: a percentage of zero
     * is not a large increase, it is an unanswerable question, and the UI says
     * so rather than rendering an infinity.
     */
    private function deltaPercent(int $current, int $previous): ?int
    {
        if ($previous <= 0) {
            return null;
        }

        return (int) round((($current - $previous) / $previous) * 100);
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
