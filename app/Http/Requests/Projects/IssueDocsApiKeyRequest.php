<?php

namespace App\Http\Requests\Projects;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IssueDocsApiKeyRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Either an existing project of the current team, or a name to create one
     * with. Nothing else: the docs panel is a shortcut, not a second projects
     * screen.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'project' => ['nullable', 'string', 'max:255'],
            'name' => ['required_without:project', 'nullable', 'string', 'max:255'],
        ];
    }
}
