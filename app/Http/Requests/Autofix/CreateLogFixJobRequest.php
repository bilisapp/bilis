<?php

namespace App\Http\Requests\Autofix;

use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\Team;
use App\Models\TeamLlmCredential;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Raising a fix job from a single log line the reader is looking at.
 *
 * The row travels in the request body because the viewer already has it and
 * ClickHouse has no primary key to re-read one by. That is safe for the same
 * reason the scanned path is: everything here ends up inside `error_context`,
 * which `TaskRenderer` hands to the agent wrapped in untrusted-data markers,
 * and a team member who can reach this endpoint can already spawn a custom job
 * with instructions of their own choosing. Nothing that decides *permissions*
 * is read from the body.
 *
 * The two things that are not taken on trust are the two that matter. The
 * project id is resolved through the team in the URL, so an id belonging to
 * another team is "no such project" rather than a permission error — the same
 * rule the log viewer itself follows. The repository is then derived from the
 * project and the row's service name through the service claims, never named
 * by the client: which codebase owns a service is a settings decision, and a
 * browser must not be able to point an agent at a different one.
 *
 * @phpstan-import-type LogRow from \App\Services\Logs\LogQuery
 */
class CreateLogFixJobRequest extends FormRequest
{
    /**
     * How much of one log body travels with the job.
     *
     * Comfortably above `TaskRenderer::STACK_LIMIT`, which is what actually
     * bounds what reaches the agent. This is only here so a malformed or
     * hostile post cannot write an unbounded blob into `error_context`.
     */
    public const BODY_LIMIT = 32000;

    /**
     * How many attributes of each bag are kept, and how long each may be.
     */
    public const ATTRIBUTE_COUNT = 100;

    public const ATTRIBUTE_LIMIT = 8000;

    /**
     * The project resolved from the submitted id, once.
     */
    protected ?Project $resolvedProject = null;

    /**
     * The repository the row's service is fixed in, once.
     */
    protected ?ProjectRepository $resolvedRepository = null;

    /**
     * Whether the repository has been looked up yet.
     */
    protected bool $repositoryResolved = false;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'project' => ['required', 'string', 'max:64'],
            'timestamp' => ['required', 'string', 'max:64'],
            'severityText' => ['nullable', 'string', 'max:64'],
            'severityNumber' => ['nullable', 'integer'],
            'serviceName' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:'.self::BODY_LIMIT],
            'traceId' => ['nullable', 'string', 'max:128'],
            'spanId' => ['nullable', 'string', 'max:128'],
            'scopeName' => ['nullable', 'string', 'max:255'],
            'scopeVersion' => ['nullable', 'string', 'max:64'],
            'logAttributes' => ['nullable', 'array', 'max:'.self::ATTRIBUTE_COUNT],
            'logAttributes.*' => ['nullable', 'string', 'max:'.self::ATTRIBUTE_LIMIT],
            'resourceAttributes' => ['nullable', 'array', 'max:'.self::ATTRIBUTE_COUNT],
            'resourceAttributes.*' => ['nullable', 'string', 'max:'.self::ATTRIBUTE_LIMIT],
            /*
             * Which of the team's model credentials pays for this run.
             * Optional, exactly as on a custom job: the controller falls back
             * to the team default.
             */
            'credential' => ['nullable', 'integer'],
        ];
    }

    /**
     * Check the log line names something fixable, once the fields are sane.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->project() === null) {
                    $validator->errors()->add('project', __('That log line does not belong to a project on this team.'));

                    return;
                }

                if ($this->repository() === null) {
                    $validator->errors()->add(
                        'repository',
                        __('No connected repository is responsible for this service yet.'),
                    );
                }
            },
        ];
    }

    /**
     * The team's project the submitted log line came from.
     *
     * ProjectId is a String column in ClickHouse, so what arrives is the id as
     * text; it is matched against the team's own projects rather than looked
     * up on its own.
     */
    public function project(): ?Project
    {
        if ($this->resolvedProject instanceof Project) {
            return $this->resolvedProject;
        }

        $team = $this->team();
        $id = $this->input('project');

        if ($team === null || ! is_numeric($id)) {
            return null;
        }

        return $this->resolvedProject = $team->projects()->whereKey((int) $id)->first();
    }

    /**
     * The autofix-enabled repository responsible for this line's service.
     */
    public function repository(): ?ProjectRepository
    {
        if ($this->repositoryResolved) {
            return $this->resolvedRepository;
        }

        $this->repositoryResolved = true;

        $project = $this->project();

        if ($project === null) {
            return null;
        }

        return $this->resolvedRepository = ProjectRepository::forService(
            $project->getKey(),
            (string) $this->string('serviceName'),
        );
    }

    /**
     * The log row, in the exact shape `LogQuery` returns and the fingerprinter reads.
     *
     * @return LogRow
     */
    public function row(): array
    {
        /** @var array<string, string> $logAttributes */
        $logAttributes = $this->attributeBag('logAttributes');
        /** @var array<string, string> $resourceAttributes */
        $resourceAttributes = $this->attributeBag('resourceAttributes');

        return [
            'projectId' => (string) $this->project()?->getKey(),
            'timestamp' => (string) $this->string('timestamp'),
            'traceId' => (string) $this->string('traceId'),
            'spanId' => (string) $this->string('spanId'),
            'severityText' => (string) $this->string('severityText'),
            'severityNumber' => (int) $this->input('severityNumber', 0),
            'serviceName' => (string) $this->string('serviceName'),
            'body' => (string) $this->string('body'),
            'scopeName' => (string) $this->string('scopeName'),
            'scopeVersion' => (string) $this->string('scopeVersion'),
            'resourceAttributes' => $resourceAttributes,
            'logAttributes' => $logAttributes,
        ];
    }

    /**
     * The model credential this request names, if it belongs to the team.
     */
    public function credential(): ?TeamLlmCredential
    {
        $id = $this->input('credential');
        $team = $this->team();

        if ($team === null || ! is_numeric($id)) {
            return null;
        }

        return $team->llmCredentials()->whereKey((int) $id)->first();
    }

    /**
     * The team the request is scoped to, from the URL rather than the body.
     */
    public function team(): ?Team
    {
        $slug = $this->route('current_team');

        if ($slug instanceof Team) {
            return $slug;
        }

        return is_string($slug) ? Team::where('slug', $slug)->first() : null;
    }

    /**
     * Read one attribute bag off the request as a map of strings.
     *
     * @return array<string, string>
     */
    private function attributeBag(string $key): array
    {
        $bag = $this->input($key);

        if (! is_array($bag)) {
            return [];
        }

        $attributes = [];

        foreach ($bag as $name => $value) {
            if (is_string($value)) {
                $attributes[(string) $name] = $value;
            }
        }

        return $attributes;
    }
}
