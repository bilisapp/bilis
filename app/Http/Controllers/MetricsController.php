<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesTeamProjects;
use App\Models\Project;
use App\Services\Metrics\HostCharts;
use App\Services\Metrics\MetricFilters;
use App\Services\Metrics\MetricQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The metrics page's two tabs: Hosts — every machine the host-metrics receiver
 * reports, and a fixed set of charts for one of them — and the explorer: one
 * metric, filtered and optionally split by an attribute, charted over a window.
 *
 * Everything a chart needs is in the query string, so a chart is a link.
 * The expensive props are deferred: the catalog (which metrics exist), the
 * selected metric's attributes (for the filter and group-by pickers) and the
 * series themselves, which load in a group of their own so the pickers render
 * before the chart does.
 */
class MetricsController extends Controller
{
    use ResolvesTeamProjects;

    /**
     * Show the metric explorer for the current team.
     *
     * A bare visit — the sidebar link, no query string at all — opens on the
     * Hosts tab when the team has hosts: that is what someone who ran the
     * installer came to see. Every explorer link carries a window, so the
     * explorer itself is never redirected away from.
     */
    public function index(Request $request, MetricQuery $metrics): Response|RedirectResponse
    {
        $team = $this->team($request);
        $projects = $this->projects($team);

        if ($request->query() === [] && $metrics->hasHosts($this->projectIds($projects, null))) {
            return to_route('metrics.hosts', ['current_team' => $team->slug]);
        }

        $filters = MetricFilters::fromRequest($request);
        $projectIds = $this->projectIds($projects, $filters->project);

        return Inertia::render('metrics/Index', [
            'projects' => $projects
                ->map(fn (Project $project): array => ['name' => $project->name, 'slug' => $project->slug])
                ->values(),
            'filters' => $filters->toArray(),
            /*
             * Asked of every project, without the window: an empty hour must
             * read as "nothing in this hour", not as "metrics are not set up".
             */
            'hasMetrics' => $metrics->hasAnyMetrics($this->projectIds($projects, null)),
            'catalog' => Inertia::defer(fn (): array => $metrics->catalog($projectIds, $filters)),
            'attributes' => Inertia::defer(fn (): array => $metrics->attributes($projectIds, $filters)),
            'series' => Inertia::defer(fn (): array => $metrics->series($projectIds, $filters), 'chart'),
        ]);
    }

    /**
     * Show the Hosts tab: one row per machine, then the selected one's charts.
     *
     * Both are deferred, the charts in a group of their own since they are
     * eight series reads. `host` defaults to the first host listed, so the tab
     * always has charts to show once anything reports.
     */
    public function hosts(Request $request, MetricQuery $metrics): Response
    {
        $team = $this->team($request);
        $filters = MetricFilters::fromRequest($request);
        $selected = $request->validate(['host' => ['nullable', 'string', 'max:255']])['host'] ?? null;
        $projects = $this->projects($team);
        $projectIds = $this->projectIds($projects, $filters->project);

        /*
         * Read once per request: the charts' request needs the list too, to
         * pick the default host and to know whether it runs containers.
         */
        $hostList = null;
        $hosts = function () use (&$hostList, $metrics, $projectIds, $filters): array {
            return $hostList ??= $metrics->hosts($projectIds, $filters);
        };

        /** The selected host's row: the one asked for, else the first. */
        $current = function () use ($hosts, $selected): ?array {
            $rows = $hosts()['hosts'];

            foreach ($rows as $row) {
                if ($row['name'] === $selected) {
                    return $row;
                }
            }

            return $selected === null ? ($rows[0] ?? null) : null;
        };

        return Inertia::render('metrics/Hosts', [
            'projects' => $projects
                ->map(fn (Project $project): array => ['name' => $project->name, 'slug' => $project->slug])
                ->values(),
            'filters' => [...$filters->toArray(), 'host' => $selected],
            'hasHosts' => $metrics->hasHosts($this->projectIds($projects, null)),
            'hosts' => Inertia::defer(fn (): array => $hosts()),
            'charts' => Inertia::defer(function () use ($metrics, $projectIds, $filters, $current): array {
                $row = $current();

                if ($row === null) {
                    return ['host' => null, 'charts' => [], 'unavailable' => false];
                }

                return ['host' => $row['name'], ...HostCharts::charts($metrics, $projectIds, $filters, $row['name'], $row['containers'] > 0)];
            }, 'charts'),
        ]);
    }
}
