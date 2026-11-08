<?php

namespace App\Http\Requests\Alerts;

/**
 * A draft rule the editor wants charted over the last 24 hours.
 *
 * The same definition rules as a saved rule — projects, source, the source's
 * own `query.*`, condition and window — without the name or channels, which
 * change nothing about what is read.
 */
class PreviewAlertRuleRequest extends SaveAlertRuleRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $source = $this->input('source');

        return [
            ...$this->definitionRules($this->team()),
            ...$this->sourceRules(is_string($source) ? $source : null),
        ];
    }
}
