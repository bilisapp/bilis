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
 * `alerts:evaluate` dispatches one {@see ScanIssueAlerts} per minute for each
 * team with an enabled channel flagged `issue_events`; the job calls
 * {@see scanTeamId()}, so a slow team never starves the next one of its
 * time budget, and a team without such a channel costs no ClickHouse read.
 *
 * - **issue_new:** an issue whose `FirstSeen` is within the last
 *   {@see LOOKBACK_SECONDS}, unless someone has already ignored it.
 * - **issue_regressed:** a resolved issue whose last event arrived after
 *   `resolved_at` — {@see IssueState}'s own rule — among the team's
 *   resolved issues whose last event arrived within the lookback. Looked up
 *   by key from the app database's resolved set, not nominated by event
 *   hour, so a late-flushed old event still counts.
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
 *
 * @phpstan-import-type AlertCandidate from IssueQuery
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
     * The teams that would receive an issue event at all, in id order — the
     * cheap check that decides which scans are dispatched.
     *
     * @return list<int>
     */
    public function subscribedTeamIds(): array
    {
        return array_values(array_map(intval(...), $this->subscribedTeams()->orderBy('id')->pluck('id')->all()));
    }

    /**
     * Scan every subscribed team in turn (what the per-team jobs do, inline).
     *
     * @return int the number of events recorded
     */
    public function scan(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');

        return array_sum(array_map(
            fn (int $teamId): int => $this->scanTeamId($teamId, $now),
            $this->subscribedTeamIds(),
        ));
    }

    /**
     * Scan one team if it still takes issue events. A failure is reported and
     * counts as nothing recorded; the next tick overlaps this one.
     *
     * @return int the number of events recorded
     */
    public function scanTeamId(int $teamId, ?CarbonImmutable $now = null): int
    {
        $team = $this->subscribedTeams()->whereKey($teamId)->first();

        if ($team === null) {
            return 0;
        }

        try {
            return $this->scanTeam($team, $now ?? CarbonImmutable::now('UTC'));
        } catch (Throwable $exception) {
            report($exception);

            return 0;
        }
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
        $projectIds = array_values(array_map('strval', $projects->keys()->all()));

        $resolved = $this->resolvedIssues($projectIds);

        $newCandidates = $this->issues->newIssueCandidates($projectIds, Carbon::instance($now->subHour()), $since);
        $regressionCandidates = $resolved === []
            ? []
            : $this->issues->regressionCandidates(
                $projectIds,
                array_values(array_unique(array_map(fn (Issue $issue): string => $issue->fingerprint, $resolved))),
                $since,
            );

        if ($newCandidates === null || $regressionCandidates === null) {
            // Overloaded: IssueQuery has reported it. The next tick overlaps this one.
            return 0;
        }

        if ($newCandidates === [] && $regressionCandidates === []) {
            return 0;
        }

        $ignored = $this->ignoredIssues($newCandidates);

        return DB::transaction(function () use ($team, $projects, $newCandidates, $regressionCandidates, $ignored, $resolved, $since, $now): int {
            $eventIds = [];
            $newRecorded = 0;

            foreach ($newCandidates as $candidate) {
                $project = $projects->get($candidate['projectId']);

                if ($project === null
                    || $candidate['firstSeen'] < $since->getTimestampMs()
                    || isset($ignored[$candidate['projectId'].':'.$candidate['fingerprint']])
                    || $newRecorded >= self::MAX_NEW_PER_TEAM
                ) {
                    continue;
                }

                $id = $this->record($team, $project, $candidate, AlertEventType::IssueNew, null, $now);

                if ($id !== null) {
                    $eventIds[] = $id;
                    $newRecorded++;
                }
            }

            foreach ($regressionCandidates as $candidate) {
                $project = $projects->get($candidate['projectId']);
                $decision = $resolved[$candidate['projectId'].':'.$candidate['fingerprint']] ?? null;

                if ($project === null
                    || $decision?->resolved_at === null
                    || $candidate['lastIngested'] <= $decision->resolved_at->getTimestampMs()
                ) {
                    continue;
                }

                $id = $this->record($team, $project, $candidate, AlertEventType::IssueRegressed, $decision, $now);

                if ($id !== null) {
                    $eventIds[] = $id;
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
     * The team's resolved issues — the only ones that can regress — keyed
     * `projectId:fingerprint`. One query.
     *
     * @param  list<string>  $projectIds
     * @return array<string, Issue>
     */
    private function resolvedIssues(array $projectIds): array
    {
        return Issue::query()
            ->whereIn('project_id', array_map('intval', $projectIds))
            ->where('status', IssueStatus::Resolved)
            ->whereNotNull('resolved_at')
            ->get()
            ->keyBy(fn (Issue $issue): string => $issue->project_id.':'.$issue->fingerprint)
            ->all();
    }

    /**
     * Which of these new-issue candidates someone has already ignored, as a
     * set keyed `projectId:fingerprint`. One query: the fingerprint list is
     * shared across projects and the pairs are matched here.
     *
     * @param  list<AlertCandidate>  $candidates
     * @return array<string, true>
     */
    private function ignoredIssues(array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $wanted = array_fill_keys(array_map(fn (array $candidate): string => $candidate['projectId'].':'.$candidate['fingerprint'], $candidates), true);

        $keys = Issue::query()
            ->whereIn('project_id', array_values(array_unique(array_map(fn (array $candidate): int => (int) $candidate['projectId'], $candidates))))
            ->whereIn('fingerprint', array_values(array_unique(array_column($candidates, 'fingerprint'))))
            ->where('status', IssueStatus::Ignored)
            ->get(['project_id', 'fingerprint'])
            ->map(fn (Issue $issue): string => $issue->project_id.':'.$issue->fingerprint)
            ->filter(fn (string $key): bool => isset($wanted[$key]))
            ->values()
            ->all();

        return array_fill_keys($keys, true);
    }

    /**
     * Insert one issue event unless its dedupe key exists.
     *
     * @param  AlertCandidate  $candidate
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
