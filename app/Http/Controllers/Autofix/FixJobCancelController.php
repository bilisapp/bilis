<?php

namespace App\Http\Controllers\Autofix;

use App\Enums\FixJobStatus;
use App\Http\Controllers\Controller;
use App\Models\FixJob;
use App\Services\Autofix\AyosClient;
use App\Services\Autofix\AyosException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class FixJobCancelController extends Controller
{
    /**
     * Ask Ayos to abort a running job.
     *
     * Cancellation is low-traffic RPC, so it goes the long way round through
     * Laravel — only the live event stream is allowed to reach Ayos from the
     * browser. Ayos still posts its (failed) artifact afterwards; the status
     * written here is the local truth in the meantime, and the callback has
     * the last word.
     */
    public function __invoke(AyosClient $ayos, string $current_team, FixJob $fixJob): RedirectResponse
    {
        Gate::authorize('cancel', $fixJob);

        try {
            $ayos->cancel($fixJob);
        } catch (AyosException $exception) {
            report($exception);

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Ayos could not be reached — the job was left running.'),
            ]);

            return back();
        }

        $fixJob->forceFill([
            'status' => FixJobStatus::Cancelled,
            'failure_reason' => 'Cancelled from the Bilis UI.',
            'completed_at' => now(),
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Fix job cancelled.')]);

        return back();
    }
}
