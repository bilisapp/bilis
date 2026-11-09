<?php

namespace App\Http\Requests\Alerts;

use App\Enums\AlertCondition;
use App\Enums\AlertSeverity;
use App\Enums\NoDataState;
use App\Models\Team;
use App\Services\Alerting\AlertSources;
use App\Services\Alerting\Sources\AlertSourceUnavailable;
use App\Services\Alerting\Sources\TraceAlertSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An alert rule as the editor sends it (specs/alerting.md, "Data model").
 *
 * `query.*` is validated by the chosen source's own {@see AlertSource::rules()},
 * so the editor and the evaluator agree on what a query may hold. Project and
 * channel ids must belong to the route's team: an id from another team is a
 * validation error, never a silently widened read.
 *
 * Authorisation is the route's: `EnsureTeamMembership` on the team prefix and
 * the team-scoped `{alertRule}` binding. Any member may write rules, as any
 * member may triage issues.
 */
class SaveAlertRuleRequest extends FormRequest
{
    /**
     * The evaluation intervals a rule may choose. A hard minimum of a minute
     * protects ClickHouse.
     */
    public const INTERVALS = [60, 300, 900];

    public const MIN_WINDOW_SECONDS = 60;

    public const MAX_WINDOW_SECONDS = 86_400;

    public const MAX_HOLD_SECONDS = 3_600;

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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $team = $this->team();
        $source = $this->input('source');

        return [
            'name' => ['required', 'string', 'max:120'],
            ...$this->definitionRules($team),
            'channel_ids' => ['sometimes', 'array'],
            'channel_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('notification_channels', 'id')->where('team_id', $team->getKey()),
            ],
            ...$this->sourceRules(is_string($source) ? $source : null),
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'project_ids.present' => 'Pick at least one project.',
            'project_ids.min' => 'Pick at least one project.',
            'project_ids.*.exists' => 'That project does not belong to this team.',
            'channel_ids.*.exists' => 'That channel does not belong to this team.',
            'window_seconds.max' => $this->input('source') === 'traces'
                ? 'A trace rule reads at most a one-hour window.'
                : 'A rule reads at most a 24-hour window.',
        ];
    }

    /**
     * What a rule reads and how it judges it — everything the preview needs too.
     *
     * @return array<string, mixed>
     */
    protected function definitionRules(Team $team): array
    {
        $maxWindow = $this->input('source') === 'traces'
            ? TraceAlertSource::MAX_WINDOW_SECONDS
            : self::MAX_WINDOW_SECONDS;

        return [
            // Null is every project of the team, now and later.
            'project_ids' => ['present', 'nullable', 'array', 'min:1'],
            'project_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('projects', 'id')->where('team_id', $team->getKey()),
            ],
            'source' => ['required', 'string', Rule::in(array_keys((array) config('alerting.sources', [])))],
            'condition' => ['required', 'string', Rule::enum(AlertCondition::class)],
            'threshold' => ['required', 'numeric', 'between:-1000000000000,1000000000000'],
            'window_seconds' => ['required', 'integer', 'min:'.self::MIN_WINDOW_SECONDS, 'max:'.$maxWindow],
            'interval_seconds' => ['required', 'integer', Rule::in(self::INTERVALS)],
            'for_seconds' => ['required', 'integer', 'min:0', 'max:'.self::MAX_HOLD_SECONDS],
            'keep_firing_for_seconds' => ['required', 'integer', 'min:0', 'max:'.self::MAX_HOLD_SECONDS],
            'no_data_state' => ['required', 'string', Rule::enum(NoDataState::class)],
            'severity' => ['required', 'string', Rule::enum(AlertSeverity::class)],
        ];
    }

    /**
     * The source's own rules for `query.*`; none when the source is unknown
     * (the `source` rule already reports that).
     *
     * @return array<string, mixed>
     */
    protected function sourceRules(?string $source): array
    {
        if ($source === null) {
            return ['query' => ['required', 'array']];
        }

        try {
            return app(AlertSources::class)->for($source)->rules();
        } catch (AlertSourceUnavailable) {
            return ['query' => ['required', 'array']];
        }
    }

    /**
     * The team in the route, resolved by slug.
     */
    public function team(): Team
    {
        $team = $this->route('current_team');

        if ($team instanceof Team) {
            return $team;
        }

        return Team::query()->where('slug', is_string($team) ? $team : '')->firstOrFail();
    }
}
