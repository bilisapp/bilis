<?php

namespace App\Http\Controllers;

use App\Enums\FixJobStatus;
use App\Enums\FixJobType;
use App\Http\Requests\Autofix\CreateFixJobRequest;
use App\Jobs\DispatchFixJob;
use App\Models\FixJob;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\Team;
use App\Models\TeamLlmCredential;
use App\Services\Autofix\FixJobBudget;
use App\Services\Autofix\LlmCredentials;
use App\Services\Autofix\StreamTokenIssuer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AutofixController extends Controller
{
    /**
     * How much of a custom request names the job in a table row.
     */
    public const TITLE_LENGTH = 80;

    /**
     * How much of a custom request is shown under the row's title.
     */
    public const EXCERPT_LENGTH = 140;

    /**
     * List the fix jobs raised for the current team's projects.
     */
    public function index(Request $request): Response
    {
        $team = $this->team($request);

        $filters = $request->validate([
            'project' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:'.implode(',', FixJobStatus::values())],
        ]);

        $project = isset($filters['project'])
            ? $team->projects()->where('slug', $filters['project'])->first()
            : null;

        $status = isset($filters['status']) ? FixJobStatus::tryFrom((string) $filters['status']) : null;

        return Inertia::render('autofix/Index', [
            'teamSlug' => $team->slug,
            'jobs' => $this->page($this->jobs($team, $project, $status)),
            'projects' => $team->projects()
                ->orderBy('name')
                ->get()
                ->map(fn (Project $project): array => [
                    'name' => $project->name,
                    'slug' => $project->slug,
                ])
                ->values(),
            'statuses' => array_map(
                fn (FixJobStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
                FixJobStatus::cases(),
            ),
            'filters' => [
                'project' => $project?->slug,
                'status' => $status?->value,
            ],
            'hasRepository' => $this->repositories($team)->exists(),
            /*
             * Only projects whose repository has opted in can be given work by
             * hand — the same gate the endpoint enforces, so the picker never
             * offers a choice the server would refuse. With the whole feature
             * switched off there is no such project, whatever the rows say.
             */
            'autofixProjects' => $this->enabled() ? $this->autofixProjects($team) : [],
            /*
             * The keys the new-job dialog may choose between. Never the keys
             * themselves — the same summary the settings page gets.
             */
            'llmCredentials' => $team->llmCredentials()
                ->get()
                ->map(fn (TeamLlmCredential $credential): array => $credential->toSummary())
                ->values(),
        ]);
    }

    /**
     * Spawn a job from typed instructions instead of from a production error.
     *
     * Everything downstream is the scheduled path unchanged: same queued
     * dispatch, same diff validation, same pull request. The only things this
     * adds are who may ask (a member of the project's team) and how often
     * (the repository's own budgets, shared with the scan — a person and the
     * scheduler draw from one pool).
     */
    public function store(CreateFixJobRequest $request, FixJobBudget $budgets, LlmCredentials $llm, string $current_team): RedirectResponse
    {
        /*
         * `AUTOFIX_ENABLED` is the deployment-wide switch the scan and the
         * verifier already honour. A repository row saying `autofix_enabled`
         * is not a second opinion about it: with the feature off, nothing is
         * dispatched, by hand or otherwise.
         */
        abort_unless($this->enabled(), 404);

        $repository = $request->repository();

        abort_if($repository === null, 404);

        Gate::authorize('create', [FixJob::class, $repository->project]);

        $refusal = $budgets->refusalReason($repository);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['project' => $refusal]);
        }

        $team = $repository->project->team;

        /*
         * Checked here rather than left to the dispatcher. Without a key the
         * job would be created, queued, and fail a moment later with a banner
         * that reads like an outage — when the real answer is a field in team
         * settings that nobody has filled in yet.
         */
        if (! $llm->configuredFor($team)) {
            throw ValidationException::withMessages([
                'credential' => __('This team has no model API key yet. Add one in team settings before running a fix job.'),
            ]);
        }

        /*
         * Pinned at creation, not read at dispatch: "which key paid for this
         * job" must have one answer, and it must not change because somebody
         * edited team settings while the run was in flight. An unnamed (or
         * unrecognised) credential means the team's default.
         */
        $credential = $request->credential() ?? $team->defaultLlmCredential();

        $job = FixJob::query()->create([
            'project_id' => $repository->project_id,
            'project_repository_id' => $repository->id,
            'team_llm_credential_id' => $credential?->getKey(),
            'type' => FixJobType::Custom,
            'fingerprint' => null,
            'error_context' => null,
            'instructions' => (string) $request->string('instructions'),
            'base_sha' => '',
            'status' => FixJobStatus::Pending,
        ]);

        DispatchFixJob::dispatch($job->uuid);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Job queued. The agent will start shortly.')]);

        return to_route('autofix.show', [$current_team, $job->uuid]);
    }

    /**
     * Show one fix job, its transcript and its diff.
     */
    public function show(Request $request, StreamTokenIssuer $streamTokens, string $current_team, FixJob $fixJob): Response
    {
        Gate::authorize('view', $fixJob);

        $fixJob->loadMissing(['project', 'repository']);

        /*
         * A stream is offered only while the job can still emit — a finished
         * job renders its persisted transcript and never touches Ayos. The
         * token itself is minted by a separate, short-lived POST so it cannot
         * sit in the page's props going stale.
         */
        $stream = $fixJob->status->isActive() && $streamTokens->isConfigured()
            ? [
                'url' => $streamTokens->streamUrl($fixJob),
                'ttlMinutes' => $streamTokens->ttlMinutes(),
            ]
            : null;

        return Inertia::render('autofix/Show', [
            'teamSlug' => $current_team,
            'job' => $this->jobDetail($fixJob),
            'stream' => $stream,
            'canCancel' => $request->user()?->can('cancel', $fixJob) ?? false,
        ]);
    }

    /**
     * Whether the autofix control plane is switched on for this deployment.
     */
    private function enabled(): bool
    {
        return config('autofix.enabled') === true;
    }

    /**
     * The fix jobs of a team, newest first, optionally narrowed.
     *
     * @return LengthAwarePaginator<int, FixJob>
     */
    private function jobs(Team $team, ?Project $project, ?FixJobStatus $status): LengthAwarePaginator
    {
        return FixJob::query()
            ->with(['project', 'repository'])
            ->whereIn('project_id', $project
                ? [$project->getKey()]
                : $team->projects()->select('id'))
            ->when($status, fn ($query) => $query->where('status', $status->value))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * The team's projects that may be handed a job by hand.
     *
     * @return list<array{name: string, slug: string}>
     */
    private function autofixProjects(Team $team): array
    {
        return array_values($this->repositories($team)
            ->where('autofix_enabled', true)
            ->with('project')
            ->get()
            ->sortBy(fn (ProjectRepository $repository): string => $repository->project->name)
            ->map(fn (ProjectRepository $repository): array => [
                'name' => $repository->project->name,
                'slug' => $repository->project->slug,
            ])
            ->values()
            ->all());
    }

    /**
     * The repositories connected to any of the team's projects.
     *
     * @return Builder<ProjectRepository>
     */
    private function repositories(Team $team): Builder
    {
        return ProjectRepository::query()->whereIn('project_id', $team->projects()->select('id'));
    }

    /**
     * Shape one page of fix jobs for the table.
     *
     * @param  LengthAwarePaginator<int, FixJob>  $paginator
     * @return array{data: list<array<string, mixed>>, currentPage: int, lastPage: int, total: int}
     */
    private function page(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_values(array_map(
                fn (FixJob $job): array => $this->jobSummary($job),
                $paginator->items(),
            )),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * The row shape the jobs table renders.
     *
     * @return array<string, mixed>
     */
    private function jobSummary(FixJob $job): array
    {
        $context = $job->error_context;
        $isCustom = $job->type === FixJobType::Custom;
        $instructions = $job->instructions;

        return [
            'uuid' => $job->uuid,
            'type' => $job->type->value,
            'typeLabel' => $job->type->label(),
            'fingerprint' => $job->fingerprint,
            'instructions' => $instructions,
            'instructionsExcerpt' => $instructions === null ? null : Str::limit(Str::squish($instructions), self::EXCERPT_LENGTH),
            /*
             * One field names the job wherever it is listed. An error job is
             * named by its exception; a custom one by the first breath of what
             * was asked for, because nothing else about it is legible.
             */
            'title' => $isCustom
                ? (Str::limit(Str::squish((string) $instructions), self::TITLE_LENGTH) ?: 'Custom job')
                : ($this->contextString($context, 'exception') ?? 'Unknown error'),
            'exception' => $this->contextString($context, 'exception') ?? ($isCustom ? null : 'Unknown error'),
            'message' => $this->contextString($context, 'message'),
            'serviceName' => $this->contextString($context, 'service_name'),
            'occurrences' => isset($context['count']) && is_numeric($context['count'])
                ? (int) $context['count']
                : null,
            'status' => $job->status->value,
            'statusLabel' => $job->status->label(),
            'isActive' => $job->status->isActive(),
            'project' => [
                'name' => $job->project->name,
                'slug' => $job->project->slug,
            ],
            'repository' => $job->repository->repo_full_name,
            'prNumber' => $job->pr_number,
            'prUrl' => $job->pr_url,
            'createdAt' => $job->created_at?->toISOString(),
            'completedAt' => $job->completed_at?->toISOString(),
        ];
    }

    /**
     * Everything the detail page renders, transcript and diff included.
     *
     * @return array<string, mixed>
     */
    private function jobDetail(FixJob $job): array
    {
        return [
            ...$this->jobSummary($job),
            'errorContext' => $job->error_context,
            'report' => $job->report,
            'events' => array_values($job->events ?? []),
            'diff' => $job->diff,
            'baseSha' => $job->base_sha,
            'defaultBranch' => $job->repository->default_branch,
            'failureReason' => $job->failure_reason,
            'dispatchedAt' => $job->dispatched_at?->toISOString(),
            'firstSeen' => $this->contextString($job->error_context, 'first_seen'),
            'lastSeen' => $this->contextString($job->error_context, 'last_seen'),
            'stack' => $this->contextString($job->error_context, 'stack'),
        ];
    }

    /**
     * Read one string out of the stored error context.
     *
     * @param  array<string, mixed>|null  $context
     */
    private function contextString(?array $context, string $key): ?string
    {
        $value = $context[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Resolve the team the request is scoped to.
     */
    private function team(Request $request): Team
    {
        $team = $request->route('current_team');

        if ($team instanceof Team) {
            return $team;
        }

        if (is_string($team)) {
            return Team::where('slug', $team)->firstOrFail();
        }

        abort(403);
    }
}
