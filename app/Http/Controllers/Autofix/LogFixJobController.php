<?php

namespace App\Http\Controllers\Autofix;

use App\Enums\FixJobType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Autofix\CreateLogFixJobRequest;
use App\Models\FixJob;
use App\Services\Autofix\FixJobBudget;
use App\Services\Autofix\FixTriggerService;
use App\Services\Autofix\LlmCredentials;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Turn one log line the reader is looking at into a fix job.
 *
 * This is the scanned path with the thresholds taken off and a person put in
 * their place. The scan waits for an error to recur five times because nobody
 * is watching; here somebody is, and they have decided this line is worth an
 * attempt on its first occurrence. Everything else is identical — the same
 * fingerprint, the same `error_context` shape, the same budgets, the same
 * queued dispatch and diff validation, the same pull request.
 */
class LogFixJobController extends Controller
{
    /**
     * Raise a fix job for the submitted log row.
     */
    public function __invoke(
        CreateLogFixJobRequest $request,
        FixTriggerService $trigger,
        FixJobBudget $budgets,
        LlmCredentials $llm,
        string $current_team,
    ): RedirectResponse {
        /*
         * The deployment-wide switch, checked before anything else — with the
         * feature off nothing is dispatched, by hand or otherwise.
         */
        abort_unless(config('autofix.enabled') === true, 404);

        $project = $request->project();
        $repository = $request->repository();

        abort_if($project === null || $repository === null, 404);

        Gate::authorize('create', [FixJob::class, $project]);

        $row = $request->row();
        $fingerprint = $trigger->fingerprintFor($row);

        /*
         * Somebody else already pressed this button, or the scan got there
         * first. Two runs against one error is two pull requests and twice the
         * spend for one answer, so the second asker is shown the first job
         * rather than being charged for a duplicate of it. Only *active* jobs
         * short-circuit: a fix that failed or was rejected is exactly what a
         * person would want to try again, and the scan's cooldown — which
         * exists to stop an unattended loop — is not theirs to serve.
         */
        $active = FixJob::query()
            ->where('project_repository_id', $repository->getKey())
            ->where('type', FixJobType::Error)
            ->where('fingerprint', $fingerprint)
            ->orderByDesc('id')
            ->get()
            ->first(fn (FixJob $job): bool => $job->status->isActive());

        if ($active !== null) {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => __('This error is already being worked on.'),
            ]);

            return to_route('autofix.show', [$current_team, $active->uuid]);
        }

        /*
         * `FixJobBudget` is the single implementation of the per-repository
         * limits, and they are shared with the scan and with custom jobs: what
         * is rationed is agent runs against one codebase.
         */
        $refusal = $budgets->refusalReason($repository);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['repository' => $refusal]);
        }

        $team = $repository->project->team;

        /*
         * Checked here rather than left to the dispatcher, for the same reason
         * the custom path checks it: without a key the job would be created,
         * queued and fail a moment later with a banner that reads like an
         * outage, when the answer is a field in team settings.
         */
        if (! $llm->configuredFor($team)) {
            throw ValidationException::withMessages([
                'credential' => __('This team has no model API key yet. Add one in team settings before running a fix job.'),
            ]);
        }

        $credential = $request->credential() ?? $team->defaultLlmCredential();

        $job = $trigger->raiseFromRow($repository, $row, $credential?->getKey());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Job queued. The agent will start shortly.'),
        ]);

        return to_route('autofix.show', [$current_team, $job->uuid]);
    }
}
