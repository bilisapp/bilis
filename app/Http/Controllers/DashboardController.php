<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Services\Logs\LogOnboarding;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, LogOnboarding $onboarding): Response
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

        return Inertia::render('Dashboard', [
            'pendingInvitations' => $pendingInvitations,
            'onboarding' => $onboarding->state(
                $team,
                array_values($projects->map(fn (Project $project): int => $project->id)->all()),
            ),
            'firstProject' => $firstProject instanceof Project
                ? ['name' => $firstProject->name, 'slug' => $firstProject->slug]
                : null,
        ]);
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
