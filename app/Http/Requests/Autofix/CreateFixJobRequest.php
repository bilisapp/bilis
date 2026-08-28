<?php

namespace App\Http\Requests\Autofix;

use App\Models\ProjectRepository;
use App\Models\Team;
use App\Models\TeamLlmCredential;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Spawning a job from typed instructions rather than from a production error.
 *
 * Two things are checked here and nothing else is taken on trust. The
 * repository is resolved through the team in the URL, so an id from another
 * team is "no such repository" rather than a permission error; and it has to
 * have opted into autofix, because the opt-in is what the whole surface hangs
 * on — a repository that has not enabled it cannot be made to run an agent by
 * hand-editing a form. Budgets are the controller's job: they are shared with
 * the scheduled path and live in `FixJobBudget`.
 */
class CreateFixJobRequest extends FormRequest
{
    /**
     * The shortest request that could plausibly mean something.
     */
    public const MIN_LENGTH = 10;

    /**
     * The longest request that travels with a job.
     */
    public const MAX_LENGTH = 10000;

    /**
     * The repository resolved from the submitted id, once.
     */
    protected ?ProjectRepository $resolved = null;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'repository' => ['required', 'integer'],
            'instructions' => ['required', 'string', 'min:'.self::MIN_LENGTH, 'max:'.self::MAX_LENGTH],
            /*
             * Which of the team's model credentials pays for this run.
             * Optional: a team with one key, or a deployment running on the
             * instance-wide key, has nothing to choose between, and the
             * controller falls back to the team default.
             */
            'credential' => ['nullable', 'integer'],
        ];
    }

    /**
     * Get the validation messages that apply to the request.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'instructions.min' => 'Say a little more about what you want changed — :min characters at least.',
            'instructions.max' => 'That is longer than a job can carry. Keep it under :max characters.',
        ];
    }

    /**
     * Normalise the request before it is validated.
     *
     * The global `TrimStrings` middleware already does this for a browser
     * request; doing it here as well means the length bounds mean the same
     * thing whatever way the request arrived by.
     */
    protected function prepareForValidation(): void
    {
        $instructions = $this->input('instructions');

        if (is_string($instructions)) {
            $this->merge(['instructions' => trim($instructions)]);
        }
    }

    /**
     * Check the repository the request names, once the fields themselves are sane.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('repository')) {
                    return;
                }

                if ($this->repository() === null) {
                    $validator->errors()->add('repository', __('Pick a repository that has autofix enabled.'));
                }
            },
        ];
    }

    /**
     * The autofix-enabled repository this request names.
     *
     * Addressed by id rather than by project slug. A project ships several
     * services and may hold a repository per group of them, so "the project's
     * repository" no longer identifies anything — it used to resolve to
     * whichever row came back first, which would have quietly sent a request
     * to the wrong codebase the moment a second one was connected.
     *
     * The id is still resolved through the team in the URL, so one from
     * another team is "no such repository" rather than a permission error.
     */
    public function repository(): ?ProjectRepository
    {
        if ($this->resolved instanceof ProjectRepository) {
            return $this->resolved;
        }

        $teamSlug = $this->route('current_team');
        $repositoryId = $this->input('repository');

        if (! is_string($teamSlug) || ! is_numeric($repositoryId)) {
            return null;
        }

        $repository = ProjectRepository::query()
            ->whereKey((int) $repositoryId)
            ->where('autofix_enabled', true)
            ->with(['project.team'])
            ->whereHas('project.team', fn ($team) => $team->where('slug', $teamSlug))
            ->first();

        return $this->resolved = $repository;
    }

    /**
     * The model credential this request names, if it belongs to the team.
     *
     * Resolved through the team rather than by id alone: a credential id from
     * another team is "no such credential" and falls back to the default,
     * never someone else's key.
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
     * The team the request is scoped to.
     */
    public function team(): ?Team
    {
        return $this->repository()?->project->team;
    }
}
