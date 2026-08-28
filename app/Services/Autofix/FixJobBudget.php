<?php

namespace App\Services\Autofix;

use App\Enums\FixJobStatus;
use App\Models\FixJob;
use App\Models\ProjectRepository;
use Illuminate\Support\Carbon;

/**
 * The one place that answers "may this repository start another job?".
 *
 * Two brakes, both per repository and both counted over *every* job the
 * repository has raised regardless of what raised it: an error the scan found
 * and a request a teammate typed consume the same capacity, because the thing
 * being rationed is agent runs against one codebase, not error reports.
 *
 * `FixTriggerService` asks before each scan and again between fingerprints;
 * the custom-job endpoint asks once before creating a row. Neither implements
 * the arithmetic itself — a second copy of it would drift, and the drift would
 * show up as a repository quietly running twice its budget.
 */
class FixJobBudget
{
    /**
     * How many more jobs this repository may have in flight.
     */
    public function availableSlots(ProjectRepository $repository): int
    {
        $active = FixJob::query()
            ->where('project_repository_id', $repository->id)
            ->whereIn('status', array_map(fn (FixJobStatus $status): string => $status->value, FixJobStatus::active()))
            ->count();

        return max(0, $repository->max_concurrent - $active);
    }

    /**
     * How many more jobs this repository may raise today.
     */
    public function remainingBudget(ProjectRepository $repository, ?Carbon $now = null): int
    {
        $now = ($now ?? Carbon::now())->clone();

        $spent = FixJob::query()
            ->where('project_repository_id', $repository->id)
            ->where('created_at', '>=', $now->startOfDay())
            ->count();

        return max(0, $repository->daily_budget - $spent);
    }

    /**
     * Say why this repository may not start another job, or null if it may.
     *
     * The message names the budget that blocked it, because "try again later"
     * is not actionable and the two limits have different remedies: one clears
     * itself when a pull request is reviewed, the other at midnight.
     */
    public function refusalReason(ProjectRepository $repository, ?Carbon $now = null): ?string
    {
        if ($this->availableSlots($repository) <= 0) {
            return __(
                ':repo already has as many fix jobs in flight as it allows (:count). Wait for one to finish, or raise the concurrency limit in project settings.',
                ['repo' => $repository->repo_full_name, 'count' => $repository->max_concurrent],
            );
        }

        if ($this->remainingBudget($repository, $now) <= 0) {
            return __(
                ':repo has used its daily budget of :count fix jobs. It resets at midnight, or you can raise the budget in project settings.',
                ['repo' => $repository->repo_full_name, 'count' => $repository->daily_budget],
            );
        }

        return null;
    }
}
