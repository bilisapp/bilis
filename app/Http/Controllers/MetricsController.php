<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesTeamProjects;
use App\Models\Project;
use App\Services\Metrics\MetricFilters;
use App\Services\Metrics\MetricQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The metric explorer: one metric, filtered and optionally split by an
 * attribute, charted over a window.
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
     */
    public function index(Request $request, MetricQuery $metrics): Response
    {
        $team = $this->team($request);
        $filters = MetricFilters::fromRequest($request);
        $projects = $this->projects($team);
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
}
