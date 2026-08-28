<?php

namespace App\Http\Requests\Autofix;

use App\Models\ProjectRepository;
use App\Models\ProjectRepositoryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The settings a connected repository carries: whether autofix may run on it
 * at all, which services it is responsible for, the command that proves a fix
 * works, and the budgets that stop a noisy service from spending a day's worth
 * of attempts in an hour.
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
            /*
             * The services this repository fixes. `*` is the catch-all — every
             * service no sibling repository has named — and is what a
             * one-repository project keeps.
             */
            'services' => ['present', 'array'],
            'services.*' => ['string', 'max:255'],
        ];
    }

    /**
     * The settings that belong on the repository row itself.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return collect($this->validated())->except('services')->all();
    }

    /**
     * The service names this repository should claim, de-duplicated.
     *
     * @return list<string>
     */
    public function services(): array
    {
        /** @var list<string> $services */
        $services = $this->validated('services', []);

        return array_values(array_unique(array_filter(
            array_map(trim(...), $services),
            fn (string $service): bool => $service !== '',
        )));
    }

    /**
     * Check the claim against the rest of the project.
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

                $repository = $this->route('repository');

                if (! $repository instanceof ProjectRepository) {
                    return;
                }

                $services = $this->services();

                /*
                 * An enabled repository that claims nothing can never be
                 * scanned. Refusing here is the difference between a mistake
                 * you are told about and autofix that appears to be on and
                 * silently does nothing for a week.
                 */
                if ($services === [] && $this->boolean('autofix_enabled')) {
                    $validator->errors()->add(
                        'services',
                        __('Name at least one service this repository is responsible for, or autofix has nothing to scan.'),
                    );

                    return;
                }

                $taken = ProjectRepositoryService::query()
                    ->where('project_id', $repository->project_id)
                    ->where('project_repository_id', '!=', $repository->getKey())
                    ->whereIn('service_name', $services)
                    ->pluck('service_name');

                /*
                 * One service, one repository. Two claims on `checkout` would
                 * raise a job on each for every checkout error — the thing this
                 * whole mapping exists to prevent.
                 */
                if ($taken->isNotEmpty()) {
                    $validator->errors()->add('services', __('Already claimed by another repository in this project: :services.', [
                        'services' => $taken->map(fn (string $service): string => $service === ProjectRepositoryService::CATCH_ALL
                            ? __('every other service')
                            : $service)->implode(', '),
                    ]));
                }
            },
        ];
    }
}
