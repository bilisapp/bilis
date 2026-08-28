<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\GitHubInstallation;
use App\Models\Team;
use App\Models\User;
use App\Services\Autofix\GitHubAppException;
use App\Services\Autofix\GitHubInstallationClient;
use App\Services\Autofix\GitHubInstallState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The single GitHub App install round trip.
 *
 * One App serves the whole product: the same one behind "Continue with GitHub"
 * is the one a team installs on its repositories to turn autofix on. So this
 * is deliberately not an OAuth flow — it hands the user to GitHub's install
 * screen and takes the App's Setup URL callback back.
 *
 * The webhook remains the source of truth for installations; everything here
 * is UX sugar so the user lands back on the repository picker instead of
 * refreshing until the webhook catches up.
 */
class GitHubInstallationController extends Controller
{
    /**
     * Send the user to GitHub to install the App on their repositories.
     */
    public function connect(Request $request, GitHubInstallState $state): RedirectResponse
    {
        $team = $this->team($request);
        $user = $request->user();

        abort_if($user === null, 403);

        $slug = config('autofix.github.slug');

        if (! is_string($slug) || trim($slug) === '') {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('This instance has no GitHub App configured.'),
            ]);

            return back();
        }

        $validated = $request->validate([
            'project' => ['nullable', 'string', 'max:255'],
        ]);

        return redirect()->away(sprintf(
            'https://github.com/apps/%s/installations/new?%s',
            trim($slug, '/'),
            http_build_query(['state' => $state->issue($team, $user, $validated['project'] ?? null)]),
        ));
    }

    /**
     * Take GitHub's Setup URL callback and record the installation.
     */
    public function setup(Request $request, GitHubInstallState $state, GitHubInstallationClient $github): RedirectResponse
    {
        $claims = $state->consume($request->query('state'));

        if ($claims === null) {
            return $this->failure(__('That GitHub install link is no longer valid. Start the connection again.'));
        }

        $team = Team::find($claims['team']);
        $user = $request->user();

        if (! $team instanceof Team || ! $user instanceof User || ! $user->belongsToTeam($team)) {
            return $this->failure(__('That GitHub install link does not belong to you.'));
        }

        $installationId = (int) $request->query('installation_id');

        if ($installationId <= 0) {
            return $this->failure(__('GitHub did not say which installation was created.'), $team, $claims['project']);
        }

        // A `request` action means the user asked an org owner to approve the
        // install; nothing exists to record yet, and saying so beats a
        // success message for something that has not happened.
        if ($request->query('setup_action') === 'request') {
            Inertia::flash('toast', [
                'type' => 'success',
                'message' => __('Your install request was sent to the organisation owners.'),
            ]);

            return $this->landing($team, $claims['project']);
        }

        $existing = GitHubInstallation::where('installation_id', $installationId)->first();

        if ($existing !== null && $existing->team_id !== $team->getKey()) {
            return $this->failure(
                __('That GitHub account is already connected to another Bilis team.'),
                $team,
                $claims['project'],
            );
        }

        try {
            $account = $github->account($installationId);
        } catch (GitHubAppException $exception) {
            report($exception);

            return $this->failure(__('GitHub could not be reached. Try connecting again.'), $team, $claims['project']);
        }

        GitHubInstallation::updateOrCreate(
            ['installation_id' => $installationId],
            [
                'team_id' => $team->getKey(),
                'account_login' => $account['login'],
                'account_type' => $account['type'],
            ],
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':account is connected. Pick the repository this project ships from.', [
                'account' => $account['login'],
            ]),
        ]);

        return $this->landing($team, $claims['project']);
    }

    /**
     * Flash an error and put the user back where they can try again.
     */
    private function failure(string $message, ?Team $team = null, ?string $projectSlug = null): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return $team instanceof Team
            ? $this->landing($team, $projectSlug)
            : redirect()->route('dashboard', ['current_team' => $this->fallbackTeamSlug()]);
    }

    /**
     * Where the callback drops the user: the project they started from when
     * there is one, the project list otherwise.
     */
    private function landing(Team $team, ?string $projectSlug): RedirectResponse
    {
        $project = $projectSlug !== null
            ? $team->projects()->where('slug', $projectSlug)->first()
            : null;

        return $project !== null
            ? redirect()->route('projects.show', [
                'current_team' => $team->slug,
                'project' => $project->slug,
                'repositories' => 1,
            ])
            : redirect()->route('projects.index', ['current_team' => $team->slug]);
    }

    /**
     * A team slug to fall back to when the state blob told us nothing.
     */
    private function fallbackTeamSlug(): string
    {
        $team = request()->user()?->currentTeam;

        return $team instanceof Team ? $team->slug : '';
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
