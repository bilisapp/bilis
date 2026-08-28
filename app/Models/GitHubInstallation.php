<?php

namespace App\Models;

use Database\Factories\GitHubInstallationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A GitHub App installation on one account or organisation, owned by a team.
 *
 * The App's private key is never stored per row — it is app level config, see
 * `config/autofix.php`.
 *
 * @property int $id
 * @property int $team_id
 * @property int $installation_id
 * @property string $account_login
 * @property string $account_type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, ProjectRepository> $repositories
 */
#[Fillable(['team_id', 'installation_id', 'account_login', 'account_type'])]
class GitHubInstallation extends Model
{
    /** @use HasFactory<GitHubInstallationFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'github_installations';

    /**
     * Get the team that owns the installation.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the repositories connected through this installation.
     *
     * @return HasMany<ProjectRepository, $this>
     */
    public function repositories(): HasMany
    {
        return $this->hasMany(ProjectRepository::class, 'github_installation_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'installation_id' => 'integer',
        ];
    }
}
