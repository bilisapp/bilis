<?php

namespace App\Http\Controllers;

use App\Http\Requests\Projects\CreateProjectApiKeyRequest;
use App\Models\Project;
use App\Models\ProjectApiKey;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ProjectApiKeyController extends Controller
{
    /**
     * Issue a new API key for the project.
     *
     * The plaintext secret key is flashed exactly once so the UI can show it
     * in a "copy it now" dialog; it is never persisted or sent again. The DSN
     * rides along for convenience only — it holds the public half, so the
     * project page can show it again at any time.
     */
    public function store(CreateProjectApiKeyRequest $request, string $current_team, Project $project): RedirectResponse
    {
        $apiKey = ProjectApiKey::generate($project, $request->validated('name'));

        Inertia::flash('newApiKey', [
            'name' => $apiKey->name,
            'key' => $apiKey->plainTextKey,
            'dsn' => $apiKey->dsn(),
        ]);

        return back();
    }

    /**
     * Revoke an API key.
     */
    public function destroy(string $current_team, Project $project, ProjectApiKey $apiKey): RedirectResponse
    {
        $apiKey->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API key revoked.')]);

        return back();
    }
}
