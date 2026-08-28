<?php

namespace App\Models;

use App\Enums\LlmProvider;
use Database\Factories\TeamLlmCredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One model credential belonging to one team.
 *
 * Bring-your-own-key, and now more than one of them: a team can hold a key per
 * provider (or several against the same provider, pointed at different
 * budgets) and choose which one a job runs on. The scope has not changed and
 * is not arbitrary — the model credential is the only thing in a job spec that
 * can cost money rather than merely propose a patch, and it travels to the
 * runner in the clear, because the platform offers no per-run secret channel
 * (Ayos's DEPLOY.md §2). A key belonging to one customer bounds the worst case
 * to that customer's own budget.
 *
 * The key itself is encrypted at rest and never leaves the server. What the
 * browser gets is the label, the provider, and the last four characters.
 *
 * @property int $id
 * @property int $team_id
 * @property LlmProvider $provider
 * @property string $label
 * @property string $api_key
 * @property string|null $hint
 * @property bool $is_default
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 */
#[Fillable(['team_id', 'provider', 'label', 'is_default', 'last_used_at'])]
class TeamLlmCredential extends Model
{
    /** @use HasFactory<TeamLlmCredentialFactory> */
    use HasFactory;

    /**
     * How much of the key is kept in the clear, so a customer can tell which
     * of their keys is which without it ever being shown again.
     */
    public const HINT_LENGTH = 4;

    /**
     * Add a credential to a team.
     *
     * The key is passed separately from the fillable attributes on purpose: it
     * is never mass-assignable, so no `update($request->all())` anywhere in the
     * application can set or replace one.
     */
    public static function add(Team $team, LlmProvider $provider, string $label, string $key): self
    {
        $key = trim($key);

        $credential = new self;

        $credential->forceFill([
            'team_id' => $team->getKey(),
            'provider' => $provider->value,
            'label' => $label,
            'api_key' => $key,
            'hint' => mb_substr($key, -self::HINT_LENGTH),
            // The first credential a team adds is the one its scheduled jobs
            // run on; there is nothing else it could be.
            'is_default' => ! $team->llmCredentials()->exists(),
        ])->save();

        return $credential;
    }

    /**
     * Make this the credential the team's jobs run on unless told otherwise.
     *
     * Done in one transaction because "the default" is a property of the team
     * rather than of any one row: a crash between clearing the old flag and
     * setting the new one would leave a team with no default at all, and the
     * scheduled scan reads that as "no key configured".
     */
    public function makeDefault(): void
    {
        DB::transaction(function (): void {
            static::query()
                ->where('team_id', $this->team_id)
                ->whereKeyNot($this->getKey())
                ->update(['is_default' => false]);

            $this->forceFill(['is_default' => true])->save();
        });
    }

    /**
     * Record that a job was dispatched on this credential.
     */
    public function markUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->save();
    }

    /**
     * The team this credential belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * What the settings page and the job picker are allowed to know.
     *
     * Never the key. Only which provider it is for, what the customer called
     * it, and the four characters that tell two keys apart.
     *
     * @return array{id: int, provider: string, providerLabel: string, label: string, hint: string|null, isDefault: bool, lastUsedAt: string|null, createdAt: string|null}
     */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider->value,
            'providerLabel' => $this->provider->label(),
            'label' => $this->label,
            'hint' => $this->hint,
            'isDefault' => $this->is_default,
            'lastUsedAt' => $this->last_used_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => LlmProvider::class,
            /*
             * Encrypted at rest with the application key, and deliberately NOT
             * fillable: it is written only through `add()`, which also records
             * the masked hint.
             */
            'api_key' => 'encrypted',
            'is_default' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }
}
