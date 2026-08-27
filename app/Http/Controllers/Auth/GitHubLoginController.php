<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Teams\CreateTeam;
use App\Http\Controllers\Controller;
use App\Http\Responses\Concerns\RedirectsToCurrentTeam;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Laravel\Socialite\Contracts\User as GitHubUser;
use Laravel\Socialite\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

/**
 * "Continue with GitHub" — the OAuth leg of logging in.
 *
 * GitHub only ever reports a primary email it has itself verified, which is
 * what makes the email match below safe to auto-link: an account that already
 * exists under that address is the same person, not a stranger claiming it.
 */
class GitHubLoginController extends Controller
{
    use RedirectsToCurrentTeam;

    public function __construct(private CreateTeam $createTeam) {}

    /**
     * Send the visitor to GitHub to authorize the app.
     */
    public function redirect(): SymfonyRedirectResponse
    {
        if (! $this->isConfigured()) {
            return $this->failed('Signing in with GitHub is not available on this instance.');
        }

        return Socialite::driver('github')->redirect();
    }

    /**
     * Receive the visitor back from GitHub and log them in.
     */
    public function callback(Request $request): RedirectResponse
    {
        if (! $this->isConfigured()) {
            return $this->failed('Signing in with GitHub is not available on this instance.');
        }

        /*
         * A visitor who declines the authorization is sent back with an
         * `error` query parameter rather than a code — nothing went wrong, so
         * say so plainly instead of reporting a failure.
         */
        if ($request->has('error')) {
            return $this->failed('GitHub sign-in was cancelled.');
        }

        try {
            $githubUser = Socialite::driver('github')->user();
        } catch (Throwable) {
            return $this->failed('We could not complete the GitHub sign-in. Please try again.');
        }

        $user = $this->resolveUser($githubUser);

        if (! $user) {
            return $this->failed('Your GitHub account has no primary email address we can use. Add one on GitHub, or log in with your email and password.');
        }

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->intended(
            $this->redirectPathForCurrentTeam($request, Fortify::redirects('login')),
        );
    }

    /**
     * Find, link, or create the local account behind this GitHub identity.
     */
    private function resolveUser(GitHubUser $githubUser): ?User
    {
        $githubId = (string) $githubUser->getId();

        if ($githubId === '') {
            return null;
        }

        if ($user = User::query()->where('github_id', $githubId)->first()) {
            return $user;
        }

        $email = (string) $githubUser->getEmail();

        if ($email === '') {
            return null;
        }

        if ($user = User::query()->where('email', $email)->first()) {
            return $this->link($user, $githubId);
        }

        return $this->create($githubId, $email, $this->nameFor($githubUser, $email));
    }

    /**
     * Attach the GitHub identity to an account that already owns the address.
     */
    private function link(User $user, string $githubId): User
    {
        $user->forceFill([
            'github_id' => $githubId,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        return $user;
    }

    /**
     * Register a new account, exactly as `CreateNewUser` does for the form:
     * a user and the personal team that owns everything they go on to create.
     *
     * There is no password to set — the column is not nullable, so it holds an
     * unguessable random value that no login form will ever match.
     */
    private function create(string $githubId, string $email, string $name): User
    {
        return DB::transaction(function () use ($githubId, $email, $name) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'github_id' => $githubId,
                'password' => Str::random(64),
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();

            $this->createTeam->handle($user, $user->name."'s Team", isPersonal: true);

            return $user;
        });
    }

    /**
     * GitHub leaves the display name blank on plenty of accounts; fall back to
     * the handle, then to the local part of the address.
     */
    private function nameFor(GitHubUser $githubUser, string $email): string
    {
        $candidates = [
            (string) $githubUser->getName(),
            (string) $githubUser->getNickname(),
            Str::before($email, '@'),
        ];

        foreach ($candidates as $candidate) {
            if (trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return 'GitHub user';
    }

    /**
     * Whether the instance has GitHub credentials at all.
     */
    private function isConfigured(): bool
    {
        return filled(config('services.github.client_id'))
            && filled(config('services.github.client_secret'));
    }

    /**
     * Back to the login page, saying what went wrong.
     */
    private function failed(string $message): RedirectResponse
    {
        return redirect()->route('login')->with('error', $message);
    }
}
