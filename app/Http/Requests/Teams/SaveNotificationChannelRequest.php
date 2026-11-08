<?php

namespace App\Http\Requests\Teams;

use App\Enums\NotificationChannelType;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Services\Alerting\NotificationChannelConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * A notification channel as the team settings dialog sends it.
 *
 * `config` is validated by {@see NotificationChannelConfig::rules()} for the
 * channel's type. A channel's type is fixed once created. On an edit, a blank
 * URL or secret means "keep the stored one" — the browser never holds them
 * ({@see NotificationChannel::toSummary()}), so it cannot send them back.
 *
 * Owners and admins only: a channel's config is a credential, guarded like the
 * team's model API keys.
 */
class SaveNotificationChannelRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $team = $this->route('team');

        return $team instanceof Team && Gate::allows('update', $team);
    }

    /**
     * Fill the blanks an edit leaves from the stored config, so validation
     * sees the channel as it will be saved.
     */
    protected function prepareForValidation(): void
    {
        $channel = $this->channel();
        $config = $this->input('config');
        $config = is_array($config) ? $config : [];

        if ($channel !== null) {
            $this->merge(['type' => $channel->type->value]);

            if ($channel->type !== NotificationChannelType::Email) {
                foreach (['url', 'secret'] as $key) {
                    $value = $config[$key] ?? null;

                    if ((! is_string($value) || trim($value) === '') && isset($channel->config[$key])) {
                        $config[$key] = $channel->config[$key];
                    }
                }
            }
        }

        if (isset($config['addresses']) && is_string($config['addresses'])) {
            $config['addresses'] = array_values(array_filter(
                array_map(trim(...), preg_split('/[\s,;]+/', $config['addresses']) ?: []),
                fn (string $address): bool => $address !== '',
            ));
        }

        if (($config['recipients'] ?? null) === 'members') {
            unset($config['addresses']);
        } else {
            unset($config['recipients']);
        }

        $this->merge(['config' => $config]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = NotificationChannelType::tryFrom((string) $this->input('type'));

        return [
            'type' => ['required', 'string', Rule::enum(NotificationChannelType::class)],
            'name' => ['required', 'string', 'max:80'],
            'enabled' => ['sometimes', 'boolean'],
            'issue_events' => ['sometimes', 'boolean'],
            ...($type === null ? ['config' => ['required', 'array']] : NotificationChannelConfig::rules($type)),
        ];
    }

    /**
     * The channel being edited, or null for a new one.
     */
    public function channel(): ?NotificationChannel
    {
        $channel = $this->route('notificationChannel');

        return $channel instanceof NotificationChannel ? $channel : null;
    }

    /**
     * The validated channel type.
     */
    public function type(): NotificationChannelType
    {
        return NotificationChannelType::from((string) $this->validated('type'));
    }

    /**
     * The validated config, with only the keys its type uses.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        /** @var array<string, mixed> $config */
        $config = (array) $this->validated('config', []);

        $keys = match ($this->type()) {
            NotificationChannelType::Email => ['recipients', 'addresses'],
            NotificationChannelType::Slack => ['url'],
            NotificationChannelType::Webhook => ['url', 'secret'],
        };

        return array_filter(
            array_intersect_key($config, array_flip($keys)),
            fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }
}
