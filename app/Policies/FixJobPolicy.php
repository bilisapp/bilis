<?php

namespace App\Policies;

use App\Models\FixJob;
use App\Models\Project;
use App\Models\User;

/**
 * Team scoping for one autofix attempt.
 *
 * The route binding already resolves a fix job through its project's team, so
 * a job from another team is a 404 before this is ever consulted. The policy
 * is the second lock: it answers the same question from the model rather than
 * from the URL, which is what keeps a job reached any other way — a queued
 * lookup, a future API — honest.
 */
class FixJobPolicy
{
    /**
     * Determine whether the user can spawn a job against a project.
     *
     * There is no job yet, so the question is asked of the project instead:
     * anyone who is a member of the team that owns it may ask its repository
     * for work, exactly as they may read the jobs the scan raised there.
     */
    public function create(User $user, Project $project): bool
    {
        return $user->belongsToTeam($project->team);
    }

    /**
     * Determine whether the user can view the fix job.
     */
    public function view(User $user, FixJob $fixJob): bool
    {
        return $this->belongsToUsersTeam($user, $fixJob);
    }

    /**
     * Determine whether the user can ask Ayos to abort the fix job.
     *
     * Only a job that is still in flight can be cancelled; a terminal one has
     * nothing left to stop, and its status must not be rewritten.
     */
    public function cancel(User $user, FixJob $fixJob): bool
    {
        return $this->belongsToUsersTeam($user, $fixJob) && $fixJob->status->isActive();
    }

    /**
     * Determine whether the user can open a live stream for the fix job.
     */
    public function stream(User $user, FixJob $fixJob): bool
    {
        return $this->belongsToUsersTeam($user, $fixJob) && $fixJob->status->isActive();
    }

    /**
     * Whether the job's project belongs to a team the user is a member of.
     */
    private function belongsToUsersTeam(User $user, FixJob $fixJob): bool
    {
        $team = $fixJob->project->team;

        return $user->belongsToTeam($team);
    }
}
