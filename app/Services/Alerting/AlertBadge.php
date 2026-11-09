<?php

namespace App\Services\Alerting;

use App\Enums\AlertState;
use App\Models\AlertInstance;
use App\Models\Team;
use App\Services\Issues\IssueBadge;
use Illuminate\Support\Facades\Cache;

/**
 * The count the sidebar puts beside "Alerts": series firing right now.
 *
 * Shaped like {@see IssueBadge}: shared with every page
 * as a deferred prop, cached per team for a minute (alerts evaluate at most
 * once a minute, so a fresher number could not say more), stored as a plain
 * array. It reads the app database only. A muted rule's firing series are still
 * counted — muting silences notifications, not the truth; a disabled rule's
 * are not, because nothing is evaluating them any more.
 *
 * @phpstan-type AlertBadgeCounts array{firing: int}
 */
class AlertBadge
{
    public const CACHE_SECONDS = 60;

    /**
     * @return AlertBadgeCounts
     */
    public function forTeam(Team $team): array
    {
        /** @var AlertBadgeCounts */
        return Cache::remember(
            "alerts:badge:{$team->getKey()}",
            self::CACHE_SECONDS,
            fn (): array => [
                'firing' => AlertInstance::query()
                    ->where('state', AlertState::Firing)
                    ->whereHas('rule', fn ($query) => $query->where('team_id', $team->getKey())->where('enabled', true))
                    ->count(),
            ],
        );
    }
}
