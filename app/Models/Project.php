<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $slug
 * @property array<int, string>|null $allowed_origins
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, ProjectApiKey> $apiKeys
 * @property-read Collection<int, ProjectRepository> $repositories
 * @property-read Collection<int, FixJob> $fixJobs
 */
#[Fillable(['team_id', 'name', 'slug', 'allowed_origins'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Project $project) {
            if (empty($project->slug)) {
                $project->slug = static::generateUniqueSlug($project->name, $project->team_id);
            }
        });
    }

    /**
     * Get the team that owns the project.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get all API keys issued for this project.
     *
     * @return HasMany<ProjectApiKey, $this>
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ProjectApiKey::class);
    }

    /**
     * Get the repositories autofix may work on for this project.
     *
     * @return HasMany<ProjectRepository, $this>
     */
    public function repositories(): HasMany
    {
        return $this->hasMany(ProjectRepository::class);
    }

    /**
     * Get the autofix jobs raised for this project.
     *
     * @return HasMany<FixJob, $this>
     */
    public function fixJobs(): HasMany
    {
        return $this->hasMany(FixJob::class);
    }

    /**
     * Whether a browser at the given origin may post to this project.
     *
     * An empty list is a closed door, not an open one: a project that has
     * never been configured for browser traffic does not answer a browser.
     */
    public function allowsOrigin(string $origin): bool
    {
        $origin = self::normalizeOrigin($origin);

        if ($origin === null) {
            return false;
        }

        foreach ($this->allowed_origins ?? [] as $allowed) {
            if ($allowed === '*' || $allowed === $origin || self::matchesWildcard($allowed, $origin)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reduce a list of user supplied origins to the ones worth storing.
     *
     * @param  array<int, mixed>  $origins
     * @return array<int, string>
     */
    public static function normalizeOrigins(array $origins): array
    {
        $normalized = [];

        foreach ($origins as $origin) {
            $value = is_string($origin) ? self::normalizeOrigin($origin) : null;

            if ($value !== null && ! in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    /**
     * Reduce one origin to `scheme://host[:port]`, or null when it is not one.
     *
     * A browser sends exactly that and nothing more, so a path, a trailing
     * slash or a stray query someone pasted from the address bar is dropped
     * rather than left to never match.
     */
    public static function normalizeOrigin(string $origin): ?string
    {
        $origin = trim($origin);

        if ($origin === '' || $origin === '*') {
            return $origin === '*' ? '*' : null;
        }

        // `null` is the literal a browser sends for a sandboxed or file origin.
        if (strtolower($origin) === 'null') {
            return null;
        }

        $parts = parse_url(str_contains($origin, '//') ? $origin : 'https://'.$origin);

        if (! is_array($parts) || ! isset($parts['host']) || $parts['host'] === '') {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');

        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = strtolower($parts['host']);

        /*
         * parse_url() is lenient enough to hand back a "host" with spaces in
         * it, so the shape is checked here: dot separated labels, optionally
         * behind a single `*.` wildcard. Anything else was never an origin.
         */
        if (preg_match('/^(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host) !== 1) {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }

    /**
     * Match an origin against a `https://*.example.com` style entry.
     *
     * The wildcard stands for exactly one subdomain label. It never crosses a
     * scheme, a port or a dot, so `https://*.example.com` is satisfied by
     * `https://app.example.com` and by neither `https://a.b.example.com` nor
     * `https://example.com.attacker.test`.
     */
    private static function matchesWildcard(string $pattern, string $origin): bool
    {
        if (! str_contains($pattern, '://*.')) {
            return false;
        }

        [$scheme, $host] = explode('://', $pattern, 2);
        $suffix = substr($host, 1);

        if (! str_starts_with($origin, $scheme.'://') || ! str_ends_with($origin, $suffix)) {
            return false;
        }

        $label = substr($origin, strlen($scheme) + 3, -strlen($suffix));

        return $label !== '' && ! str_contains($label, '.');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allowed_origins' => 'array',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Generate a slug that is unique within the given team.
     */
    protected static function generateUniqueSlug(string $name, int $teamId, ?int $excludeId = null): string
    {
        $defaultSlug = Str::slug($name);

        $query = static::query()
            ->where('team_id', $teamId)
            ->where(function ($query) use ($defaultSlug) {
                $query->where('slug', $defaultSlug)
                    ->orWhere('slug', 'like', $defaultSlug.'-%');
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $existingSlugs = $query->pluck('slug');

        if ($existingSlugs->isEmpty()) {
            return $defaultSlug;
        }

        $maxSuffix = $existingSlugs
            ->map(function (string $slug) use ($defaultSlug): ?int {
                if ($slug === $defaultSlug) {
                    return 0;
                } elseif (preg_match('/^'.preg_quote($defaultSlug, '/').'-(\d+)$/', $slug, $matches)) {
                    return (int) $matches[1];
                }

                return null;
            })
            ->filter(fn (?int $suffix) => $suffix !== null)
            ->max() ?? 0;

        return $defaultSlug.'-'.($maxSuffix + 1);
    }
}
