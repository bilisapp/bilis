<?php

namespace App\Http\Controllers;

use App\Enums\AlertCondition;
use App\Enums\AlertRuleHealth;
use App\Enums\AlertSeverity;
use App\Enums\AlertState;
use App\Enums\NoDataState;
use App\Http\Controllers\Concerns\ResolvesTeamProjects;
use App\Http\Requests\Alerts\PreviewAlertRuleRequest;
use App\Http\Requests\Alerts\SaveAlertRuleRequest;
use App\Models\AlertEvent;
use App\Models\AlertInstance;
use App\Models\AlertRule;
use App\Models\NotificationChannel;
use App\Models\Project;
use App\Models\Team;
use App\Services\Alerting\AlertMessage;
use App\Services\Alerting\AlertSources;
use App\Services\Alerting\Sources\AlertSourceUnavailable;
use App\Services\Alerting\Sources\LogAlertSource;
use App\Services\Alerting\Sources\MetricAlertSource;
use App\Services\Alerting\Sources\TraceAlertSource;
use App\Services\ClickHouse\ClickHouseException;
use App\Services\Logs\LogFilters;
use App\Services\Logs\LogQuery;
use App\Services\Logs\SeverityLevel;
use App\Services\Metrics\MetricFilters;
use App\Services\Metrics\MetricQuery;
use App\Services\Support\TimeBuckets;
use App\Services\Traces\TraceFilters;
use App\Services\Traces\TraceQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Alerts page and the rule editor (specs/alerting.md, "Plans, MCP, UI").
 *
 * Everything here reads and writes the app database; the only ClickHouse read
 * is the editor's preview (one {@see AlertSource::preview()} over the last
 * 24 hours) and the editor's deferred pickers. Rules are bound through the
 * team in the route (`AppServiceProvider`), so another team's rule is a 404.
 */
class AlertsController extends Controller
{
    use ResolvesTeamProjects;

    /**
     * How long "mute until" can say, in seconds; `off` unmutes.
     */
    public const MUTE_DURATIONS = ['1h' => 3_600, '1d' => 86_400, '1w' => 604_800];

    /**
     * What the editor's preview covers.
     */
    public const PREVIEW_HOURS = 24;

    /**
     * The most history rows the History tab lists.
     */
    private const HISTORY_LIMIT = 100;

    /**
     * The fields that say what a rule is, which provisioning would otherwise
     * overwrite on a new template version.
     */
    private const DEFINITION_FIELDS = [
        'name', 'source', 'project_ids', 'query', 'condition', 'threshold', 'window_seconds',
        'interval_seconds', 'for_seconds', 'keep_firing_for_seconds', 'no_data_state', 'severity',
    ];

    /**
     * What is firing, what is waiting on `for`, and every rule with its health.
     */
    public function index(Request $request): Response
    {
        $team = $this->team($request);

        $rules = $team->alertRules()
            ->with(['notificationChannels' => fn ($query) => $query->orderBy('name')])
            ->withCount([
                'instances as firing_count' => fn ($query) => $query->where('state', AlertState::Firing),
                'instances as pending_count' => fn ($query) => $query->where('state', AlertState::Pending),
            ])
            ->orderBy('name')
            ->get();

        $instances = AlertInstance::query()
            ->with('rule')
            ->whereIn('alert_rule_id', $rules->modelKeys())
            ->whereIn('state', [AlertState::Firing, AlertState::Pending])
            ->get()
            ->sortBy(fn (AlertInstance $instance): array => [
                $instance->state === AlertState::Firing ? 0 : 1,
                -($instance->fired_at ?? $instance->active_at)?->getTimestamp(),
            ])
            ->values();

        return Inertia::render('alerts/Index', [
            ...$this->shared($team),
            'tab' => 'rules',
            'rules' => $rules->map(fn (AlertRule $rule): array => $this->ruleSummary($rule))->values(),
            'instances' => $instances->map(fn (AlertInstance $instance): array => $this->instanceSummary($instance))->values(),
            'overdue' => $this->overdueCount($rules),
            'templateCount' => $rules->filter(fn (AlertRule $rule): bool => $rule->isTemplate() && $rule->enabled)->count(),
        ]);
    }

    /**
     * The team's alert history, newest first.
     */
    public function history(Request $request): Response
    {
        $team = $this->team($request);

        $events = AlertEvent::query()
            ->with(['rule', 'team'])
            ->where('team_id', $team->getKey())
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get();

        return Inertia::render('alerts/Index', [
            ...$this->shared($team),
            'tab' => 'history',
            'events' => $events->map(fn (AlertEvent $event): array => $this->eventSummary($event))->values(),
            'historyLimit' => self::HISTORY_LIMIT,
        ]);
    }

    /**
     * The editor for a new rule.
     */
    public function create(Request $request, LogQuery $logs, TraceQuery $traces, MetricQuery $metrics): Response
    {
        $team = $this->team($request);

        return $this->editor($team, null, $logs, $traces, $metrics);
    }

    /**
     * Save a new rule; it is due at the scheduler's next tick.
     */
    public function store(SaveAlertRuleRequest $request): RedirectResponse
    {
        $team = $request->team();

        DB::transaction(function () use ($request, $team): void {
            $rule = new AlertRule([
                ...$this->attributes($request),
                'team_id' => $team->getKey(),
                'created_by' => $request->user()?->getKey(),
                'enabled' => true,
            ]);
            $rule->save();

            $rule->notificationChannels()->sync($this->channelIds($request));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Alert rule created.')]);

        return to_route('alerts.index', ['current_team' => $team->slug]);
    }

    /**
     * The editor for an existing rule.
     */
    public function edit(Request $request, string $currentTeam, AlertRule $alertRule, LogQuery $logs, TraceQuery $traces, MetricQuery $metrics): Response
    {
        return $this->editor($this->team($request), $alertRule, $logs, $traces, $metrics);
    }

    /**
     * Save a rule's changes; like a new rule, it is due now and its health
     * starts over.
     *
     * A template rule whose definition really changed is stamped
     * `customized_at`, so provisioning stops updating it. Its channels alone
     * (like muting or switching it off) are not a customisation: they say
     * where an alert goes, not what it is.
     */
    public function update(SaveAlertRuleRequest $request, string $currentTeam, AlertRule $alertRule): RedirectResponse
    {
        DB::transaction(function () use ($request, $alertRule): void {
            $attributes = $this->attributes($request);

            if ($alertRule->isTemplate() && $alertRule->customized_at === null && $this->definitionChanged($alertRule, $attributes)) {
                $attributes['customized_at'] = now();
            }

            $alertRule->fill($attributes)->save();
            $alertRule->notificationChannels()->sync($this->channelIds($request));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Alert rule saved.')]);

        return to_route('alerts.index', ['current_team' => $request->team()->slug]);
    }

    /**
     * Delete a rule. Its instances go with it; its history stays, unlinked.
     *
     * A template rule is turned off instead and its row kept: provisioning
     * recreates a missing template, never one that is switched off
     * (specs/alerting-defaults.md §3) — the same as switching it off.
     */
    public function destroy(Request $request, string $currentTeam, AlertRule $alertRule): RedirectResponse
    {
        if ($alertRule->isTemplate()) {
            $alertRule->forceFill(['enabled' => false])->save();

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Alert rule turned off.')]);

            return to_route('alerts.index', ['current_team' => $this->team($request)->slug]);
        }

        $alertRule->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Alert rule deleted.')]);

        return to_route('alerts.index', ['current_team' => $this->team($request)->slug]);
    }

    /**
     * Mute a rule for an hour, a day or a week, or unmute it. A muted rule
     * still evaluates and records; it sends nothing.
     */
    public function mute(Request $request, string $currentTeam, AlertRule $alertRule): RedirectResponse
    {
        $validated = $request->validate([
            'until' => ['required', 'string', Rule::in([...array_keys(self::MUTE_DURATIONS), 'off'])],
        ]);

        $until = $validated['until'];

        $alertRule->forceFill([
            'muted_until' => $until === 'off' ? null : now()->addSeconds(self::MUTE_DURATIONS[$until]),
        ])->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $until === 'off' ? __('Alert rule unmuted.') : __('Alert rule muted.'),
        ]);

        return back();
    }

    /**
     * Switch a rule on or off. Switched back on, it is due at the next tick.
     */
    public function toggle(Request $request, string $currentTeam, AlertRule $alertRule): RedirectResponse
    {
        $enabled = ! $alertRule->enabled;

        $alertRule->forceFill([
            'enabled' => $enabled,
            'next_evaluation_at' => $enabled ? null : $alertRule->next_evaluation_at,
        ])->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $enabled ? __('Alert rule enabled.') : __('Alert rule disabled.'),
        ]);

        return back();
    }

    /**
     * The draft rule's reading over the last 24 hours, for the editor's chart.
     *
     * A store that cannot answer is a 200 with `unavailable` — the editor says
     * "busy, try again", never an error page. A query the source refuses for
     * what it is (a percentile on a gauge) carries that reason; a ClickHouse
     * failure carries none, because its message is not written for a reader.
     */
    public function preview(PreviewAlertRuleRequest $request, AlertSources $sources): JsonResponse
    {
        $team = $request->team();
        $rule = new AlertRule($this->definition($request));
        $rule->team_id = $team->getKey();

        $projectIds = array_values(array_map(
            fn (Project $project): string => (string) $project->getKey(),
            $team->projects()->when($rule->project_ids !== null, fn ($query) => $query->whereKey($rule->project_ids))->get()->all(),
        ));

        $now = CarbonImmutable::now('UTC');
        $from = $now->subHours(self::PREVIEW_HOURS);
        $step = max($rule->window_seconds, TimeBuckets::interval($from, $now, TimeBuckets::MAX_BUCKETS));
        $offset = $rule->query['compare']['offset_seconds'] ?? null;

        // A compared bucket is divided by the bucket one offset back, which
        // exists only when the step divides the offset.
        while (is_int($offset) && $offset % $step !== 0) {
            $step++;
        }

        $from = $from->setTimestamp(intdiv($from->getTimestamp(), $step) * $step);

        try {
            $series = $sources->for($rule->source)->preview($rule, $projectIds, $from, $now, $step);
        } catch (AlertSourceUnavailable $unavailable) {
            $busy = $unavailable->getPrevious() instanceof ClickHouseException
                || str_starts_with($unavailable->getMessage(), 'ClickHouse');

            return response()->json([
                'series' => [],
                'unavailable' => true,
                'reason' => $busy ? null : $unavailable->getMessage(),
                'stepSeconds' => $step,
                'windowSeconds' => $rule->window_seconds,
                'scaled' => false,
            ]);
        }

        $scaled = $this->isCount($rule) && $step !== $rule->window_seconds;
        $factor = $rule->window_seconds / $step;

        return response()->json([
            'series' => array_map(fn (array $line): array => [
                'labels' => (object) $line['labels'],
                'points' => array_map(
                    fn (array $point): array => [$point[0], $point[1] === null ? null : ($scaled ? $point[1] * $factor : $point[1])],
                    $line['points'],
                ),
            ], $series),
            'unavailable' => false,
            'reason' => null,
            'stepSeconds' => $step,
            'windowSeconds' => $rule->window_seconds,
            'scaled' => $scaled,
        ]);
    }

    /**
     * Props both tabs carry: whether there is anywhere to send an alert, and
     * the count each tab shows.
     *
     * @return array<string, mixed>
     */
    private function shared(Team $team): array
    {
        return [
            'hasChannels' => $team->notificationChannels()->exists(),
            'hasProjects' => $team->projects()->exists(),
            'ruleCount' => $team->alertRules()->count(),
        ];
    }

    /**
     * The editor, for a new rule (null) or an existing one.
     */
    private function editor(Team $team, ?AlertRule $rule, LogQuery $logs, TraceQuery $traces, MetricQuery $metrics): Response
    {
        $projects = $this->projects($team);
        $projectIds = $this->projectIds($projects, null);

        $rule?->load(['notificationChannels', 'instances' => fn ($query) => $query->whereIn('state', [AlertState::Firing, AlertState::Pending])]);

        return Inertia::render('alerts/Edit', [
            'rule' => $rule === null ? null : [
                ...$this->formValues($rule),
                'enabled' => $rule->enabled,
                'mutedUntil' => $rule->muted_until?->toIso8601String(),
                'health' => $rule->health->value,
                'lastError' => $rule->last_error,
                'lastEvaluatedAt' => $rule->last_evaluated_at?->toIso8601String(),
                ...$this->templateFacts($rule),
                'instances' => $rule->instances
                    ->each(fn (AlertInstance $instance) => $instance->setRelation('rule', $rule))
                    ->map(fn (AlertInstance $instance): array => $this->instanceSummary($instance))
                    ->values(),
            ],
            'defaults' => $this->defaults($projects),
            'projects' => $projects->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
            ])->values(),
            'channels' => $team->notificationChannels()->orderBy('name')->get()
                ->map(fn (NotificationChannel $channel): array => [
                    'id' => $channel->id,
                    'name' => $channel->name,
                    'type' => $channel->type->value,
                    'enabled' => $channel->enabled,
                ])->values(),
            'options' => $this->options(),
            'services' => Inertia::defer(fn (): array => $this->services($projectIds, $logs, $traces), 'pickers'),
            'metricCatalog' => Inertia::defer(fn (): array => $this->metricCatalog($projectIds, $metrics), 'pickers'),
        ]);
    }

    /**
     * What a new rule starts as: "more than 25 error logs in five minutes"
     * over every project, the rule most teams write first.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<string, mixed>
     */
    private function defaults(Collection $projects): array
    {
        return [
            'name' => '',
            'project_ids' => $projects->modelKeys(),
            'source' => 'logs',
            'query' => [
                'filters' => ['services' => [], 'severities' => ['error', 'fatal'], 'search' => ''],
                'aggregation' => 'count',
                'group_by' => null,
            ],
            'condition' => AlertCondition::GreaterThan->value,
            'threshold' => 25,
            'window_seconds' => 300,
            'interval_seconds' => 60,
            'for_seconds' => 0,
            'keep_firing_for_seconds' => 0,
            'no_data_state' => NoDataState::Inactive->value,
            'severity' => AlertSeverity::Warning->value,
            'channel_ids' => [],
        ];
    }

    /**
     * What each source offers the editor, from the sources' own constants so
     * the form can never offer what validation refuses.
     *
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'sources' => array_keys((array) config('alerting.sources', [])),
            'logs' => [
                'aggregations' => LogAlertSource::AGGREGATIONS,
                'groupBy' => LogAlertSource::GROUP_BY,
                'severities' => SeverityLevel::values(),
            ],
            'traces' => [
                'aggregations' => TraceAlertSource::AGGREGATIONS,
                'groupBy' => TraceAlertSource::GROUP_BY,
                'kinds' => TraceAlertSource::KINDS,
                'maxWindowSeconds' => TraceAlertSource::MAX_WINDOW_SECONDS,
            ],
            'metrics' => [
                'aggregations' => MetricAlertSource::AGGREGATIONS,
                'maxFilters' => MetricFilters::MAX_WHERE,
            ],
            'conditions' => AlertCondition::values(),
            'intervals' => SaveAlertRuleRequest::INTERVALS,
            'noDataStates' => NoDataState::values(),
            'severities' => AlertSeverity::values(),
            'maxWindowSeconds' => SaveAlertRuleRequest::MAX_WINDOW_SECONDS,
            'maxHoldSeconds' => SaveAlertRuleRequest::MAX_HOLD_SECONDS,
            'previewHours' => self::PREVIEW_HOURS,
        ];
    }

    /**
     * Service names seen in logs and traces, for the services picker. A busy
     * store yields what the other one knows, or nothing.
     *
     * @param  list<string>  $projectIds
     * @return list<string>
     */
    private function services(array $projectIds, LogQuery $logs, TraceQuery $traces): array
    {
        $now = Carbon::now('UTC');
        $names = [];

        try {
            $names = [...$names, ...$logs->services($projectIds, new LogFilters(from: $now->clone()->subDay(), to: $now))];
        } catch (ClickHouseException) {
            // The picker is a suggestion; free text still works.
        }

        try {
            $names = [...$names, ...$traces->services($projectIds, new TraceFilters(from: $now->clone()->subDay(), to: $now))];
        } catch (ClickHouseException) {
            // As above.
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * The metrics the team's projects reported lately, for the metric picker.
     *
     * @param  list<string>  $projectIds
     * @return array{metrics: list<array{name: string, type: string, unit: string}>, unavailable: bool}
     */
    private function metricCatalog(array $projectIds, MetricQuery $metrics): array
    {
        $now = Carbon::now('UTC');

        try {
            $catalog = $metrics->catalog($projectIds, new MetricFilters(from: $now->clone()->subHour(), to: $now));
        } catch (ClickHouseException) {
            return ['metrics' => [], 'unavailable' => true];
        }

        return [
            'metrics' => array_map(
                fn (array $entry): array => ['name' => $entry['name'], 'type' => $entry['type'], 'unit' => $entry['unit']],
                $catalog['metrics'],
            ),
            'unavailable' => $catalog['unavailable'],
        ];
    }

    /**
     * The validated rule, as model attributes, due now with its health reset.
     *
     * @return array<string, mixed>
     */
    private function attributes(SaveAlertRuleRequest $request): array
    {
        return [
            ...$this->definition($request),
            'name' => (string) $request->validated('name'),
            'next_evaluation_at' => null,
            'health' => AlertRuleHealth::Unknown,
            'last_error' => null,
        ];
    }

    /**
     * What a rule reads and how it judges it.
     *
     * @return array<string, mixed>
     */
    private function definition(SaveAlertRuleRequest $request): array
    {
        /** @var array<string, mixed> $query */
        $query = $request->validated('query', []);

        return [
            'source' => (string) $request->validated('source'),
            'project_ids' => $request->validated('project_ids') === null
                ? null
                : array_values(array_unique(array_map(intval(...), (array) $request->validated('project_ids')))),
            'query' => $this->cleanQuery($query),
            'condition' => AlertCondition::from((string) $request->validated('condition')),
            'threshold' => (float) $request->validated('threshold'),
            'window_seconds' => (int) $request->validated('window_seconds'),
            'interval_seconds' => (int) $request->validated('interval_seconds'),
            'for_seconds' => (int) $request->validated('for_seconds'),
            'keep_firing_for_seconds' => (int) $request->validated('keep_firing_for_seconds'),
            'no_data_state' => NoDataState::from((string) $request->validated('no_data_state')),
            'severity' => AlertSeverity::from((string) $request->validated('severity')),
        ];
    }

    /**
     * Drop the empty fields a form sends (a blank search, no services, a null
     * group-by), so a stored query says only what it filters on.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function cleanQuery(array $query): array
    {
        $filters = is_array($query['filters'] ?? null) ? $query['filters'] : [];

        $filters = array_filter(
            $filters,
            fn (mixed $value): bool => ! ($value === null || $value === '' || $value === []),
        );

        $query['filters'] = $filters;

        if ($filters === []) {
            unset($query['filters']);
        }

        if (($query['group_by'] ?? null) === null || $query['group_by'] === '') {
            unset($query['group_by']);
        }

        if (! isset($query['compare']['offset_seconds'])) {
            unset($query['compare']);
        } else {
            $query['compare'] = ['offset_seconds' => (int) $query['compare']['offset_seconds']];
        }

        if (($query['min_events'] ?? null) === null) {
            unset($query['min_events']);
        } else {
            $query['min_events'] = (int) $query['min_events'];
        }

        return $query;
    }

    /**
     * Whether the saved definition differs from the stored one. Compared by
     * meaning, not encoding: key order and empty fields the form sends do not
     * count as a change.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function definitionChanged(AlertRule $rule, array $attributes): bool
    {
        foreach (self::DEFINITION_FIELDS as $field) {
            if ($this->canonical($rule->getAttribute($field)) !== $this->canonical($attributes[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A value reduced to what it means: enums to their value, numbers to
     * floats, associative arrays key-sorted with empty members dropped.
     */
    private function canonical(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $value = array_map($this->canonical(...), $value);

        if (array_is_list($value)) {
            return $value;
        }

        $value = array_filter($value, fn (mixed $member): bool => ! ($member === null || $member === '' || $member === []));
        ksort($value, SORT_STRING);

        return $value;
    }

    /**
     * @return list<int>
     */
    private function channelIds(SaveAlertRuleRequest $request): array
    {
        return array_values(array_unique(array_map(intval(...), (array) $request->validated('channel_ids', []))));
    }

    /**
     * Whether the rule's value is a count of things in the window, which the
     * preview scales from its bucket width to the rule's window.
     */
    private function isCount(AlertRule $rule): bool
    {
        // A compared count is a ratio ("× normal"), which no bucket width changes.
        return in_array($rule->source, ['logs', 'traces'], true)
            && ($rule->query['aggregation'] ?? null) === 'count'
            && ! isset($rule->query['compare']);
    }

    /**
     * Enabled rules whose next evaluation is more than one interval late: the
     * scheduler or the queue is not keeping up.
     *
     * @param  Collection<int, AlertRule>  $rules
     */
    private function overdueCount(Collection $rules): int
    {
        $now = now();

        return $rules->filter(fn (AlertRule $rule): bool => $rule->enabled
            && $rule->next_evaluation_at !== null
            && $rule->next_evaluation_at->getTimestamp() + $rule->interval_seconds < $now->getTimestamp()
        )->count();
    }

    /**
     * The editor's fields, as the form posts them back.
     *
     * @return array<string, mixed>
     */
    private function formValues(AlertRule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'project_ids' => $rule->project_ids === null ? null : array_map(intval(...), $rule->project_ids),
            'source' => $rule->source,
            'query' => (object) $rule->query,
            'condition' => $rule->condition->value,
            'threshold' => $rule->threshold,
            'window_seconds' => $rule->window_seconds,
            'interval_seconds' => $rule->interval_seconds,
            'for_seconds' => $rule->for_seconds,
            'keep_firing_for_seconds' => $rule->keep_firing_for_seconds,
            'no_data_state' => $rule->no_data_state->value,
            'severity' => $rule->severity->value,
            'channel_ids' => $rule->notificationChannels->modelKeys(),
        ];
    }

    /**
     * One rule in the list.
     *
     * @return array<string, mixed>
     */
    private function ruleSummary(AlertRule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'source' => $rule->source,
            'query' => (object) $rule->query,
            'condition' => $rule->condition->value,
            'threshold' => $rule->threshold,
            'windowSeconds' => $rule->window_seconds,
            'intervalSeconds' => $rule->interval_seconds,
            'forSeconds' => $rule->for_seconds,
            'severity' => $rule->severity->value,
            'enabled' => $rule->enabled,
            'mutedUntil' => $rule->isMutedAt(now()) ? $rule->muted_until?->toIso8601String() : null,
            'health' => $rule->health->value,
            'lastError' => $rule->last_error,
            'lastEvaluatedAt' => $rule->last_evaluated_at?->toIso8601String(),
            'firing' => (int) ($rule->getAttribute('firing_count') ?? 0),
            'pending' => (int) ($rule->getAttribute('pending_count') ?? 0),
            ...$this->templateFacts($rule),
            'channels' => $rule->notificationChannels->map(fn (NotificationChannel $channel): array => [
                'id' => $channel->id,
                'name' => $channel->name,
                'type' => $channel->type->value,
                'enabled' => $channel->enabled,
            ])->values()->all(),
        ];
    }

    /**
     * Whether Bilis set the rule up, why, and whether a person has edited it
     * since (specs/alerting-defaults.md §4).
     *
     * @return array{template: bool, why: string|null, customized: bool}
     */
    private function templateFacts(AlertRule $rule): array
    {
        $why = $rule->template()['why'] ?? null;

        return [
            'template' => $rule->isTemplate(),
            'why' => is_string($why) && $why !== '' ? $why : null,
            'customized' => $rule->customized_at !== null,
        ];
    }

    /**
     * One firing or pending series.
     *
     * @return array<string, mixed>
     */
    private function instanceSummary(AlertInstance $instance): array
    {
        return $instance->toSummary();
    }

    /**
     * One line of history, with the link a notification carried.
     *
     * @return array<string, mixed>
     */
    private function eventSummary(AlertEvent $event): array
    {
        $message = AlertMessage::fromEvent($event);

        return [
            'id' => $event->id,
            'type' => $event->type->value,
            'ruleId' => $event->alert_rule_id,
            'ruleName' => $event->rule?->name,
            'source' => $event->rule?->source,
            'query' => $event->rule === null ? null : (object) $event->rule->query,
            'condition' => $event->rule?->condition->value,
            'labels' => (object) $event->labels,
            'value' => $event->value,
            'threshold' => $event->threshold,
            'occurredAt' => $event->occurred_at->toIso8601String(),
            'headline' => $message->headline,
            'href' => $message->url,
        ];
    }
}
