<?php

namespace App\Models;

use Database\Factories\ProjectApiKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string $key_prefix
 * @property string $key_hash
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 */
#[Fillable(['project_id', 'name', 'key_prefix', 'key_hash', 'last_used_at'])]
#[Hidden(['key_hash'])]
class ProjectApiKey extends Model
{
    /** @use HasFactory<ProjectApiKeyFactory> */
    use HasFactory;

    /**
     * The prefix every plaintext key is issued with.
     */
    public const KEY_PREFIX = 'bilis_';

    /**
     * The number of random characters appended after the key prefix.
     */
    public const RANDOM_LENGTH = 40;

    /**
     * The number of leading plaintext characters retained for display.
     */
    public const DISPLAY_PREFIX_LENGTH = 12;

    /**
     * The minimum interval, in seconds, between `last_used_at` writes.
     */
    public const LAST_USED_THROTTLE_SECONDS = 60;

    /**
     * The plaintext key, only ever populated by `generate()`.
     */
    public ?string $plainTextKey = null;

    /**
     * Issue a new API key for the given project.
     *
     * The plaintext key is available on the returned model's `plainTextKey`
     * property and is never persisted, so it can only be shown once.
     */
    public static function generate(Project $project, string $name): self
    {
        $plainTextKey = self::KEY_PREFIX.Str::random(self::RANDOM_LENGTH);

        $apiKey = $project->apiKeys()->create([
            'name' => $name,
            'key_prefix' => Str::substr($plainTextKey, 0, self::DISPLAY_PREFIX_LENGTH),
            'key_hash' => self::hashKey($plainTextKey),
        ]);

        $apiKey->plainTextKey = $plainTextKey;

        return $apiKey;
    }

    /**
     * Find the API key matching the given plaintext key.
     */
    public static function findByPlainKey(string $key): ?self
    {
        $key = trim($key);

        if ($key === '') {
            return null;
        }

        return static::query()
            ->where('key_hash', self::hashKey($key))
            ->first();
    }

    /**
     * Hash a plaintext key for storage and lookup.
     */
    public static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * Get the project the API key belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Record that the key was just used, at most once per throttle window.
     */
    public function markAsUsed(): void
    {
        if ($this->last_used_at?->gt(now()->subSeconds(self::LAST_USED_THROTTLE_SECONDS))) {
            return;
        }

        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }
}
