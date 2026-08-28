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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, ProjectApiKey> $apiKeys
 * @property-read Collection<int, ProjectRepository> $repositories
 * @property-read Collection<int, FixJob> $fixJobs
 */
#[Fillable(['team_id', 'name', 'slug'])]
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
