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
 * Two things are checked here and nothing else is taken on trust. The project
 * is resolved through the team in the URL, so a slug from another team is
 * "no such project" rather than a permission error; and its repository has to
 * have opted into autofix, because the opt-in is what the whole surface hangs
 * on — a project that has not enabled it cannot be made to run an agent by
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
     * The repository resolved from the submitted project slug, once.
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
            'project' => ['required', 'string', 'max:255'],
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
     * Check the project the request names, once the fields themselves are sane.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('project')) {
                    return;
                }

                $repository = $this->repository();

                if ($repository === null) {
                    $validator->errors()->add('project', __('Pick a project whose repository has autofix enabled.'));
                }
            },
        ];
    }

    /**
     * The autofix-enabled repository of the project this request names.
     */
    public function repository(): ?ProjectRepository
    {
        if ($this->resolved instanceof ProjectRepository) {
            return $this->resolved;
        }

        $teamSlug = $this->route('current_team');
        $projectSlug = $this->input('project');

        if (! is_string($teamSlug) || ! is_string($projectSlug)) {
            return null;
        }

        $repository = ProjectRepository::query()
            ->where('autofix_enabled', true)
            ->with(['project.team'])
            ->whereHas('project', fn ($query) => $query
                ->where('slug', $projectSlug)
                ->whereHas('team', fn ($team) => $team->where('slug', $teamSlug)))
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
