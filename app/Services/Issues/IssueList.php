<?php

namespace App\Services\Issues;

use App\Models\Issue;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;

/**
 * Assembles the issue list: nominate, look up, merge state, filter, page.
 *
 * ClickHouse answers which issues occurred in the window and what each one
 * is; the app database answers what a person decided about it. The two are
 * joined here, over the bounded candidate set, because the status filter
 * needs both halves — "unresolved" includes a resolved issue that has
 * occurred since, which neither store can say alone.
 *
 * @phpstan-import-type IssueCandidate from IssueQuery
 * @phpstan-import-type IssueSummary from IssueQuery
 *
 * @phpstan-type IssueListItem array{fingerprint: string, projectId: string, projectSlug: string, projectName: string, type: string, message: string, origin: string, service: string, services: list<string>, environments: list<string>, firstSeen: int, lastSeen: int, events: int, users: int, windowEvents: int, windowUsers: int, series: list<int>, status: string, isNew: bool, firstRelease: string, lastRelease: string}
 * @phpstan-type IssueListResult array{issues: list<IssueListItem>, total: int, page: int, perPage: int, truncated: bool, unavailable: bool, regressed: int, services: list<string>, environments: list<string>}
 */
class IssueList
{
    public function __construct(private readonly IssueQuery $query) {}

    /**
     * Build one page of the list.
     *
     * @param  Collection<int, Project>  $projects  the team's projects, already narrowed by the project filter
     * @return IssueListResult
     */
    public function build(Collection $projects, IssueFilters $filters): array
    {
        $empty = [
            'issues' => [],
            'total' => 0,
            'page' => $filters->page,
            'perPage' => IssueFilters::PER_PAGE,
            'truncated' => false,
            'unavailable' => false,
            'regressed' => 0,
            'services' => [],
            'environments' => [],
        ];

        $projectIds = array_values($projects->map(fn (Project $project): string => (string) $project->getKey())->all());

        if ($projectIds === []) {
            return $empty;
        }

        $from = $filters->from();
        $candidates = $this->query->candidates($projectIds, $from, $filters->to);
        $summaries = $candidates === null
            ? null
            : $this->query->summaries($projectIds, array_column($candidates, 'fingerprint'));

        if ($candidates === null || $summaries === null) {
            return [...$empty, 'unavailable' => true];
        }

        $states = $this->states($projectIds, array_column($candidates, 'fingerprint'));
        /** @var array<string, Project> $projectsById */
        $projectsById = $projects->keyBy(fn (Project $project): string => (string) $project->getKey())->all();

        $items = [];
        $services = [];
        $environments = [];

        foreach ($candidates as $candidate) {
            $key = $candidate['projectId'].':'.$candidate['fingerprint'];
            $summary = $summaries[$key] ?? null;
            $project = $projectsById[$candidate['projectId']] ?? null;

            /*
             * An hourly row whose summary has aged out (the summary keeps 90
             * days, the hours 30, so this is only a race at the TTL edge) has
             * no headline to show.
             */
            if ($summary === null || $project === null) {
                continue;
            }

            array_push($services, ...$summary['services']);
            array_push($environments, ...$summary['environments']);

            $item = $this->item($candidate, $summary, $project, $states[$key] ?? null, $from->getTimestampMs(), $filters);

            if ($this->passes($item, $filters)) {
                $items[] = $item;
            }
        }

        $items = $this->sort($items, $filters->sort);
        $offset = ($filters->page - 1) * IssueFilters::PER_PAGE;

        $services = array_values(array_unique($services));
        $environments = array_values(array_unique($environments));
        sort($services);
        sort($environments);

        return [
            'issues' => array_slice($items, $offset, IssueFilters::PER_PAGE),
            'total' => count($items),
            'page' => $filters->page,
            'perPage' => IssueFilters::PER_PAGE,
            'truncated' => count($candidates) >= IssueQuery::CANDIDATE_LIMIT,
            'unavailable' => false,
            'regressed' => count(array_filter($items, fn (array $item): bool => $item['status'] === IssueState::REGRESSED)),
            'services' => $services,
            'environments' => $environments,
        ];
    }

    /**
     * Lay an hourly series out as one point per step across the window.
     *
     * Every point covers exactly `$step` seconds counted from the window's
     * start, so no point ever holds two buckets and draws a spike that is not
     * there; only the last point is short, as the window ends now. The step is
     * an hour for windows up to two days and a day beyond.
     *
     * @param  array<int, int>  $series  Unix hour => events
     * @return list<int>
     */
    public static function sparkline(array $series, int $fromSeconds, int $toSeconds, int $step): array
    {
        $points = max(1, (int) ceil(max(1, $toSeconds - $fromSeconds) / $step));
        $values = array_fill(0, $points, 0);

        foreach ($series as $hour => $events) {
            $index = intdiv(max(0, $hour - $fromSeconds), $step);

            if ($index < $points) {
                $values[$index] += $events;
            }
        }

        return array_values($values);
    }

    /**
     * The sparkline step for a window: hourly up to two days, daily beyond.
     */
    public static function sparklineStep(IssueFilters $filters): int
    {
        return IssueFilters::PERIODS[$filters->period] <= 48 ? 3600 : 86400;
    }

    /**
     * The person's decisions for these issues, keyed `projectId:fingerprint`.
     *
     * @param  list<string>  $projectIds
     * @param  list<string>  $fingerprints
     * @return array<string, Issue>
     */
    private function states(array $projectIds, array $fingerprints): array
    {
        $states = [];

        // Chunked: SQLite caps bound parameters, and a window can nominate a thousand.
        foreach (array_chunk(array_values(array_unique($fingerprints)), 500) as $chunk) {
            Issue::query()
                ->whereIn('project_id', $projectIds)
                ->whereIn('fingerprint', $chunk)
                ->get()
                ->each(function (Issue $issue) use (&$states): void {
                    $states[$issue->project_id.':'.$issue->fingerprint] = $issue;
                });
        }

        return $states;
    }

    /**
     * @param  IssueCandidate  $candidate
     * @param  IssueSummary  $summary
     * @return IssueListItem
     */
    private function item(array $candidate, array $summary, Project $project, ?Issue $state, int $fromMs, IssueFilters $filters): array
    {
        return [
            'fingerprint' => $summary['fingerprint'],
            'projectId' => $summary['projectId'],
            'projectSlug' => $project->slug,
            'projectName' => $project->name,
            'type' => $summary['type'],
            'message' => $summary['message'],
            'origin' => $summary['origin'],
            'service' => $summary['service'],
            'services' => $summary['services'],
            'environments' => $summary['environments'],
            'firstSeen' => $summary['firstSeen'],
            'lastSeen' => $summary['lastSeen'],
            'events' => $summary['events'],
            'users' => $summary['users'],
            'windowEvents' => $candidate['windowEvents'],
            'windowUsers' => $candidate['windowUsers'],
            'series' => self::sparkline($candidate['series'], intdiv($fromMs, 1000), $filters->to->getTimestamp(), self::sparklineStep($filters)),
            'status' => IssueState::of($state, $summary['lastIngested']),
            'isNew' => $summary['firstSeen'] >= $fromMs,
            'firstRelease' => $summary['firstRelease'],
            'lastRelease' => $summary['lastRelease'],
        ];
    }

    /**
     * @param  IssueListItem  $item
     */
    private function passes(array $item, IssueFilters $filters): bool
    {
        if (! IssueState::matches($item['status'], $filters->status)) {
            return false;
        }

        if ($filters->service !== null && ! in_array($filters->service, $item['services'], true)) {
            return false;
        }

        if ($filters->environment !== null && ! in_array($filters->environment, $item['environments'], true)) {
            return false;
        }

        if ($filters->query !== null) {
            $haystack = mb_strtolower($item['type']."\n".$item['message']."\n".$item['origin']."\n".$item['fingerprint']);

            if (! str_contains($haystack, mb_strtolower($filters->query))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<IssueListItem>  $items
     * @return list<IssueListItem>
     */
    private function sort(array $items, string $sort): array
    {
        usort($items, fn (array $a, array $b): int => match ($sort) {
            'events' => [$b['windowEvents'], $b['lastSeen']] <=> [$a['windowEvents'], $a['lastSeen']],
            'users' => [$b['windowUsers'], $b['windowEvents']] <=> [$a['windowUsers'], $a['windowEvents']],
            'first_seen' => [$b['firstSeen'], $b['lastSeen']] <=> [$a['firstSeen'], $a['lastSeen']],
            default => [$b['lastSeen'], $b['windowEvents']] <=> [$a['lastSeen'], $a['windowEvents']],
        });

        return $items;
    }
}
