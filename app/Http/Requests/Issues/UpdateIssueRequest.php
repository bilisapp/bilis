<?php

namespace App\Http\Requests\Issues;

use App\Enums\IssueStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A person resolving, ignoring or reopening an issue.
 *
 * Authorisation is the route's: `EnsureTeamMembership` on the team prefix and
 * the team-scoped `{project}` binding. Any member may triage, as any member
 * may raise a fix from the log viewer.
 */
class UpdateIssueRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::enum(IssueStatus::class)],
        ];
    }
}
