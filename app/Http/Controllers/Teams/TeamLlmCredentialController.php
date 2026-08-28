<?php

namespace App\Http\Controllers\Teams;

use App\Enums\LlmProvider;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\TeamLlmCredential;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * The model credentials a team runs its fix jobs on.
 *
 * Bring-your-own-key, one row per key, and a separate endpoint from the rest of
 * team settings on purpose: these values are never sent to the browser, never
 * mass-assignable, and written through `TeamLlmCredential::add()` alone.
 * Folding them into the general team update would make a credential one
 * careless `update()` away from anyone who can rename a team.
 *
 * A key is what the customer's jobs spend against. It travels to the runner
 * inside the job spec, where the platform gives no secret channel, so a key
 * scoped to one customer with a budget at the provider is the containment —
 * see Ayos's DEPLOY.md §2.
 */
class TeamLlmCredentialController extends Controller
{
    /**
     * Add a credential to the team.
     */
    public function store(Request $request, Team $team): RedirectResponse
    {
        // The same permission that renames the team: an owner or admin, never
        // an ordinary member.
        Gate::authorize('update', $team);

        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in(LlmProvider::values())],
            'label' => ['required', 'string', 'max:60'],
            'api_key' => ['required', 'string', 'min:20', 'max:500'],
        ], [
            'api_key.min' => __('That does not look like an API key.'),
        ]);

        TeamLlmCredential::add(
            $team,
            LlmProvider::from($validated['provider']),
            $validated['label'],
            $validated['api_key'],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Model API key saved.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Make one credential the team's default.
     *
     * The only thing about a stored credential that can be changed. A key
     * itself is never edited in place — a replacement is a new row and a
     * deleted old one, so the hint a customer sees always describes the value
     * actually in use.
     */
    public function update(Team $team, TeamLlmCredential $credential): RedirectResponse
    {
        Gate::authorize('update', $team);

        abort_unless($credential->team_id === $team->getKey(), 404);

        $credential->makeDefault();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Default model API key updated.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Remove a credential.
     *
     * Jobs that ran on it keep their history — the column is nulled, not
     * cascaded — and if this was the default, the oldest remaining credential
     * takes over rather than the team being left with none.
     */
    public function destroy(Team $team, TeamLlmCredential $credential): RedirectResponse
    {
        Gate::authorize('update', $team);

        abort_unless($credential->team_id === $team->getKey(), 404);

        $wasDefault = $credential->is_default;

        $credential->delete();

        if ($wasDefault) {
            $team->llmCredentials()->first()?->makeDefault();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Model API key removed.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }
}
