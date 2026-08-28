<?php

namespace App\Http\Requests\Autofix;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The settings a connected repository carries: whether autofix may run on it
 * at all, the command that proves a fix works, and the budgets that stop a
 * noisy service from spending a day's worth of attempts in an hour.
 */
class SaveProjectRepositoryRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'autofix_enabled' => ['required', 'boolean'],
            'test_cmd' => ['nullable', 'string', 'max:255'],
            'max_concurrent' => ['required', 'integer', 'min:1', 'max:10'],
            'daily_budget' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }
}
