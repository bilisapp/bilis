<?php

namespace App\Services\Alerting;

use App\Enums\AlertEventType;
use App\Enums\IssueStatus;
use App\Jobs\ScanIssueAlerts;
use App\Models\AlertEvent;
use App\Models\Issue;
use App\Models\NotificationChannel;
use App\Models\Project;
use App\Models\Team;
use App\Services\Issues\IssueQuery;
use App\Services\Issues\IssueState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * New and regressed issue events (specs/alerting.md, "Issue events").
 *
 * `alerts:evaluate` dispatches {@see ScanIssueAlerts} every minute, but only
 * while some team has an enabled channel flagged `issue_events`; the job calls
 * {@see scan()}, which reads ClickHouse once per such team and nobody else.
 *
 * - **issue_new:** an issue whose `FirstSeen` is within the last
 *   {@see LOOKBACK_SECONDS}, unless someone has already ignored it.
 * - **issue_regressed:** a resolved issue whose last event arrived after
 *   `resolved_at` — {@see IssueState}'s own rule —
 *   among issues whose last event arrived within the lookback.
 *
 * The lookback overlaps consecutive scans on purpose; `dedupe_key` makes the
 * overlap harmless. The regression key carries `resolved_at`, so resolving the
 * issue again and seeing it come back notifies again.
 *
 * At most {@see MAX_NEW_PER_TEAM} new-issue events are recorded per team per
 * scan, oldest first; the rest are skipped (a later overlapping scan may still
 * record them while they are inside the lookback). The Issues page lists
 * every one regardless.
 *
 * A storage failure for one team is reported and that team skipped; nothing
 * here throws out of the job.
 */
class IssueAlertScan
{
    /**
     * How far back one scan looks. Three one-minute ticks overlap, so a late
     * tick or a slow async insert does not lose an issue.
     */
    public const int LOOKBACK_SECONDS = 180;

    /**
     * The most new-issue events one team gets from one scan.
     */
    public const int MAX_NEW_PER_TEAM = 50;

    public function __construct(
        private readonly IssueQuery $issues,
        private readonly AlertNotifier $notifier,
    ) {}

    /**
     * Whether any team would receive an issue event at all — the cheap check
     * that decides whether a scan is dispatched.
     */
    public function hasSubscribers(): bool
    {
        return $this->subscribedTeams()->exists();
    }

    /**
     * Scan every subscribed team.
     *
     * @return int the number of events recorded
     */
    public function scan(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        $recorded = 0;

        foreach ($this->subscribedTeams()->get() as $team) {
            try {
                $recorded += $this->scanTeam($team, $now);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $recorded;
    }

    /**
     * Scan one team's projects and record what is new or regressed.
     *
     * @return int the number of events recorded
     */
    public function scanTeam(Team $team, CarbonImmutable $now): int
    {
        /** @var Collection<int, Project> $projects */
        $projects = $team->projects()->get()->keyBy(fn (Project $project): string => (string) $project->id);

        if ($projects->isEmpty()) {
            return 0;
        }

        $since = Carbon::instance($now->subSeconds(self::LOOKBACK_SECONDS));

        $candidates = $this->issues->alertCandidates(
            array_values(array_map('strval', $projects->keys()->all())),
            Carbon::instance($now->subHour()),
            $since,
            $since,
        );

        if ($candidates === null) {
            // Overloaded: IssueQuery has reported it. The next tick overlaps this one.
            return 0;
        }

        if ($candidates === []) {
            return 0;
        }

        $decisions = $this->decisions($candidates);

        return DB::transaction(function () use ($team, $projects, $candidates, $decisions, $since, $now): int {
            $eventIds = [];
            $newRecorded = 0;

            foreach ($candidates as $candidate) {
                $project = $projects->get($candidate['projectId']);

                if ($project === null) {
                    continue;
                }

                $decision = $decisions[$candidate['projectId'].':'.$candidate['fingerprint']] ?? null;

                if ($candidate['firstSeen'] >= $since->getTimestampMs()
                    && $decision?->status !== IssueStatus::Ignored
                    && $newRecorded < self::MAX_NEW_PER_TEAM
                ) {
                    $id = $this->record($team, $project, $candidate, AlertEventType::IssueNew, null, $now);

                    if ($id !== null) {
                        $eventIds[] = $id;
                        $newRecorded++;
                    }
                }

                if ($decision !== null
                    && $decision->status === IssueStatus::Resolved
                    && $decision->resolved_at !== null
                    && $candidate['lastIngested'] > $decision->resolved_at->getTimestampMs()
                ) {
                    $id = $this->record($team, $project, $candidate, AlertEventType::IssueRegressed, $decision, $now);

                    if ($id !== null) {
                        $eventIds[] = $id;
                    }
                }
            }

            if ($eventIds !== []) {
                DB::afterCommit(function () use ($eventIds): void {
                    AlertEvent::query()->whereKey($eventIds)->orderBy('id')->get()
                        ->each(fn (AlertEvent $event) => $this->notifier->notify($event));
                });
            }

            return count($eventIds);
        });
    }

    /**
     * Teams with at least one enabled channel that takes issue events.
     *
     * @return Builder<Team>
     */
    private function subscribedTeams(): Builder
    {
        return Team::query()->whereIn(
            'id',
            NotificationChannel::query()
                ->select('team_id')
                ->where('enabled', true)
                ->where('issue_events', true),
        );
    }

    /**
     * The people's decisions about these issues, keyed `projectId:fingerprint`.
     *
     * @param  list<array{projectId: string, fingerprint: string}>  $candidates
     * @return array<string, Issue>
     */
    private function decisions(array $candidates): array
    {
        $decisions = [];

        foreach (collect($candidates)->groupBy('projectId') as $projectId => $group) {
            Issue::query()
                ->where('project_id', (int) $projectId)
                ->whereIn('fingerprint', $group->pluck('fingerprint')->all())
                ->get()
                ->each(function (Issue $issue) use (&$decisions): void {
                    $decisions[$issue->project_id.':'.$issue->fingerprint] = $issue;
                });
        }

        return $decisions;
    }

    /**
     * Insert one issue event unless its dedupe key exists.
     *
     * @param  array{projectId: string, fingerprint: string, firstSeen: int, lastIngested: int, events: int, type: string, message: string, service: string}  $candidate
     * @return int|null the new event's id, or null when it was already recorded
     */
    private function record(Team $team, Project $project, array $candidate, AlertEventType $type, ?Issue $decision, CarbonImmutable $now): ?int
    {
        $base = sprintf('issue:%s:%s', $candidate['projectId'], $candidate['fingerprint']);

        $dedupeKey = $type === AlertEventType::IssueNew
            ? $base.':new'
            : $base.':regressed:'.$decision?->resolved_at?->getTimestamp();

        // Never in the future: FirstSeen is the client's clock.
        $occurredAt = $type === AlertEventType::IssueNew
            ? CarbonImmutable::createFromTimestampMsUTC(min($candidate['firstSeen'], $now->getTimestampMs()))
            : CarbonImmutable::createFromTimestampMsUTC(min($candidate['lastIngested'], $now->getTimestampMs()));

        $event = (new AlertEvent)->forceFill([
            'team_id' => $team->id,
            'alert_rule_id' => null,
            'alert_instance_id' => null,
            'type' => $type,
            'labels' => [],
            'value' => null,
            'threshold' => null,
            'occurred_at' => $occurredAt,
            'context' => array_filter([
                'project_id' => $project->id,
                'project_slug' => $project->slug,
                'project_name' => $project->name,
                'fingerprint' => $candidate['fingerprint'],
                'exception_type' => Str::limit($candidate['type'], 200),
                'message' => Str::limit($candidate['message'], 500),
                'service' => $candidate['service'],
                'events' => $candidate['events'],
                'first_seen' => CarbonImmutable::createFromTimestampMsUTC($candidate['firstSeen'])->toIso8601ZuluString('millisecond'),
                'resolved_at' => $decision?->resolved_at?->clone()->utc()->toIso8601ZuluString(),
            ], fn (mixed $value): bool => $value !== null),
            'dedupe_key' => $dedupeKey,
            'created_at' => now(),
        ]);

        if (AlertEvent::query()->insertOrIgnore($event->getAttributes()) === 0) {
            return null;
        }

        $id = AlertEvent::query()->where('dedupe_key', $dedupeKey)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }
}
