<?php

namespace App\Models;

use App\Enums\NotificationChannelType;
use Database\Factories\NotificationChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Where a team's alerts go: email, a Slack incoming webhook or a signed
 * generic webhook.
 *
 * `config` is encrypted at rest — it holds addresses, webhook URLs (a Slack
 * URL is itself a credential) and the `whsec_` signing secret — and hidden from
 * serialisation, so it never reaches the browser by accident.
 *
 * @property int $id
 * @property int $team_id
 * @property NotificationChannelType $type
 * @property string $name
 * @property array<string, mixed> $config
 * @property bool $enabled
 * @property bool $issue_events
 * @property Carbon|null $last_failed_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, AlertRule> $alertRules
 */
#[Fillable([
    'team_id',
    'type',
    'name',
    'config',
    'enabled',
    'issue_events',
    'last_failed_at',
    'last_error',
])]
#[Hidden(['config'])]
class NotificationChannel extends Model
{
    /** @use HasFactory<NotificationChannelFactory> */
    use HasFactory;

    /**
     * What the team settings page may show about the channel.
     *
     * A Slack or webhook URL is a credential (whoever holds it can post), so
     * only its host leaves the server; the `whsec_` secret never does, beyond
     * whether one is set. Email addresses are shown, since they are what the
     * edit dialog edits and they grant nothing.
     *
     * @return array{id: int, type: string, name: string, enabled: bool, issueEvents: bool, target: string, recipients: string|null, addresses: list<string>, host: string|null, hasSecret: bool, lastFailedAt: string|null, lastError: string|null}
     */
    public function toSummary(): array
    {
        $config = $this->config;
        $url = is_string($config['url'] ?? null) ? $config['url'] : null;
        $host = $url === null ? null : (parse_url($url, PHP_URL_HOST) ?: null);
        $members = ($config['recipients'] ?? null) === 'members';
        $addresses = $members || ! is_array($config['addresses'] ?? null)
            ? []
            : array_values(array_filter($config['addresses'], is_string(...)));

        $target = match ($this->type) {
            NotificationChannelType::Email => $members
                ? 'Every team member'
                : implode(', ', array_slice($addresses, 0, 2)).(count($addresses) > 2 ? ' +'.(count($addresses) - 2) : ''),
            NotificationChannelType::Slack, NotificationChannelType::Webhook => $host === null ? 'No URL' : $host.' (URL hidden)',
        };

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => $this->name,
            'enabled' => $this->enabled,
            'issueEvents' => $this->issue_events,
            'target' => $target,
            'recipients' => $members ? 'members' : null,
            'addresses' => $addresses,
            'host' => $host,
            'hasSecret' => is_string($config['secret'] ?? null) && $config['secret'] !== '',
            'lastFailedAt' => $this->last_failed_at?->toIso8601String(),
            'lastError' => $this->last_error,
        ];
    }

    /**
     * Get the team the channel belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the rules that notify this channel.
     *
     * @return BelongsToMany<AlertRule, $this>
     */
    public function alertRules(): BelongsToMany
    {
        return $this->belongsToMany(AlertRule::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => NotificationChannelType::class,
            'config' => 'encrypted:array',
            'enabled' => 'boolean',
            'issue_events' => 'boolean',
            'last_failed_at' => 'datetime',
        ];
    }
}
