<?php

namespace App\Http\Requests\Projects;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveBrowserOriginsRequest extends FormRequest
{
    /**
     * The most origins one project may allow.
     */
    private const MAX_ORIGINS = 25;

    /**
     * Turn the textarea the form posts into a list of candidate origins.
     *
     * One per line is how a list of URLs is written by hand, so that is what
     * the field accepts; commas are tolerated because people paste them.
     *
     * An emptied textarea arrives as null — `ConvertEmptyStringsToNull` sees
     * to that — and means an empty list, not a missing field: clearing the box
     * is how someone closes the door again.
     */
    protected function prepareForValidation(): void
    {
        $origins = $this->input('origins');

        if (is_array($origins)) {
            return;
        }

        $lines = is_string($origins) ? preg_split('/[\r\n,]+/', $origins) ?: [] : [];

        $this->merge([
            'origins' => array_values(array_filter(array_map('trim', $lines), fn (string $line): bool => $line !== '')),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'origins' => ['present', 'array', 'max:'.self::MAX_ORIGINS],
            'origins.*' => ['string', 'max:255'],
        ];
    }
}
