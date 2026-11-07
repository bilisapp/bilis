<?php

namespace App\Services\Issues;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * What the issue list was asked for.
 *
 * The window is a named period rather than a free from/to: issues are counted
 * per hour (`issue_hourly`), so a fifteen-minute window would promise a
 * precision the counts do not have. Thirty days is the ceiling because that is
 * how long the hourly rows and the events themselves live.
 *
 * Status, environment and the text search are applied in PHP over the bounded
 * candidate set (see {@see IssueQuery::CANDIDATE_LIMIT}); project and service
 * are pushed into SQL.
 */
class IssueFilters
{
    /**
     * The periods the list offers, in hours.
     */
    public const PERIODS = [
        '24h' => 24,
        '7d' => 24 * 7,
        '14d' => 24 * 14,
        '30d' => 24 * 30,
    ];

    public const DEFAULT_PERIOD = '7d';

    public const STATUSES = ['unresolved', 'resolved', 'ignored', 'all'];

    public const SORTS = ['last_seen', 'events', 'users', 'first_seen'];

    public const PER_PAGE = 50;

    public function __construct(
        public readonly string $period = self::DEFAULT_PERIOD,
        public readonly ?string $project = null,
        public readonly ?string $service = null,
        public readonly ?string $environment = null,
        public readonly string $status = 'unresolved',
        public readonly ?string $query = null,
        public readonly string $sort = 'last_seen',
        public readonly int $page = 1,
        public readonly Carbon $to = new Carbon,
    ) {}

    /**
     * Build the filters from the query string, falling back on bad values.
     *
     * A link with a stale or hand-edited value should still open the list, so
     * nothing here fails validation; an unknown value is the default.
     */
    public static function fromRequest(Request $request): self
    {
        $period = (string) $request->query('period', self::DEFAULT_PERIOD);
        $status = (string) $request->query('status', 'unresolved');
        $sort = (string) $request->query('sort', 'last_seen');

        return new self(
            period: array_key_exists($period, self::PERIODS) ? $period : self::DEFAULT_PERIOD,
            project: self::trimmedOrNull($request->query('project')),
            service: self::trimmedOrNull($request->query('service')),
            environment: self::trimmedOrNull($request->query('environment')),
            status: in_array($status, self::STATUSES, true) ? $status : 'unresolved',
            query: self::trimmedOrNull($request->query('query'), 255),
            sort: in_array($sort, self::SORTS, true) ? $sort : 'last_seen',
            page: max(1, min(100, (int) $request->query('page', '1'))),
            to: Carbon::now(),
        );
    }

    /**
     * The start of the window: the hour the period reaches back into.
     */
    public function from(): Carbon
    {
        return $this->to->clone()->utc()->subHours(self::PERIODS[$this->period])->startOfHour();
    }

    /**
     * The filters the way the page echoes them back.
     *
     * @return array{period: string, project: string|null, service: string|null, environment: string|null, status: string, query: string|null, sort: string, page: int}
     */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'project' => $this->project,
            'service' => $this->service,
            'environment' => $this->environment,
            'status' => $this->status,
            'query' => $this->query,
            'sort' => $this->sort,
            'page' => $this->page,
        ];
    }

    private static function trimmedOrNull(mixed $value, int $maxLength = 255): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $maxLength);
    }
}
