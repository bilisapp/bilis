<?php

namespace App\Services\Autofix;

use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * The `state` parameter carried through the GitHub App install round trip.
 *
 * GitHub's Setup URL is one fixed absolute URL for the whole App, so the
 * callback arrives with no team in the path and no guarantee it is even the
 * same browser. `state` is what closes that gap: an encrypted blob naming the
 * team and the user who started the install, which GitHub echoes back
 * verbatim.
 *
 * Three properties matter, and all three are enforced here rather than left to
 * the controller: it is **tamper proof** (Laravel's encrypter is authenticated,
 * so an edited blob fails to decrypt rather than decoding into another team),
 * it **expires** after a short window, and it is **single use** — the nonce is
 * claimed on consumption, so a replayed callback is refused even inside the
 * window.
 */
class GitHubInstallState
{
    /**
     * How long a state blob stays acceptable.
     */
    public const TTL_MINUTES = 10;

    /**
     * The cache key prefix used to burn a nonce once it has been consumed.
     */
    public const CACHE_PREFIX = 'autofix:github:install-state:';

    /**
     * Mint a state blob for one team, optionally remembering where to return.
     */
    public function issue(Team $team, User $user, ?string $projectSlug = null): string
    {
        return Crypt::encryptString((string) json_encode([
            'team' => $team->getKey(),
            'user' => $user->getKey(),
            'project' => $projectSlug,
            'exp' => now()->addMinutes(self::TTL_MINUTES)->getTimestamp(),
            'nonce' => (string) Str::uuid(),
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * Read a state blob back, or null when it is forged, stale or replayed.
     *
     * @return array{team: int, user: int, project: string|null}|null
     */
    public function consume(?string $state): ?array
    {
        if (! is_string($state) || $state === '') {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($state), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $expiresAt = $decoded['exp'] ?? null;
        $nonce = $decoded['nonce'] ?? null;
        $team = $decoded['team'] ?? null;
        $user = $decoded['user'] ?? null;
        $project = $decoded['project'] ?? null;

        if (! is_int($expiresAt) || ! is_string($nonce) || ! is_int($team) || ! is_int($user)) {
            return null;
        }

        if ($expiresAt < now()->getTimestamp()) {
            return null;
        }

        // `add` is the atomic claim: the first callback wins the nonce, every
        // replay of the same blob finds it taken and is turned away.
        if (! Cache::add(self::CACHE_PREFIX.$nonce, true, now()->addMinutes(self::TTL_MINUTES + 1))) {
            return null;
        }

        return [
            'team' => $team,
            'user' => $user,
            'project' => is_string($project) && $project !== '' ? $project : null,
        ];
    }
}
