<?php

namespace App\Http\Requests\Autofix;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Connecting a project to the repository the agent may work on.
 *
 * The repository is named here, but it is never taken on trust: the
 * controller checks it against the repositories GitHub says the installation
 * was actually granted, so a hand-edited form cannot reach a repo the team
 * never shared.
 */
class ConnectProjectRepositoryRequest extends FormRequest
{
    /**
     * Only an owner or admin may change what a project runs or accepts.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && Gate::allows('manageProjects', $project->team);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'installation_id' => ['required', 'integer'],
            'repo_full_name' => ['required', 'string', 'max:255'],
        ];
    }
}
