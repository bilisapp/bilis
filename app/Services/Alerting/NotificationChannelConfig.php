<?php

namespace App\Services\Alerting;

use App\Enums\NotificationChannelType;
use App\Notifications\Channels\SlackWebhookChannel;
use App\Services\Alerting\Webhooks\SafeWebhookUrl;
use App\Services\Alerting\Webhooks\StandardWebhookSigner;
use App\Services\Alerting\Webhooks\UnsafeWebhookUrl;
use Closure;
use InvalidArgumentException;

/**
 * The shape of a notification channel's `config`, per type — validation
 * rules for the channel form, and the one default it fills in.
 *
 * - email: `{"recipients": "members"}` (the team's members) or
 *   `{"addresses": ["oncall@example.com", …]}`
 * - slack: `{"url": "https://hooks.slack.com/…"}`
 * - webhook: `{"url": "https://…", "secret": "whsec_…"}`
 *
 * URLs are checked by {@see SafeWebhookUrl::validate()} (syntax only — the
 * address is resolved and checked again at every send).
 */
final class NotificationChannelConfig
{
    public const MAX_ADDRESSES = 20;

    /**
     * Laravel validation rules for the request's `config` key.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(NotificationChannelType $type): array
    {
        return match ($type) {
            NotificationChannelType::Email => [
                'config' => ['required', 'array'],
                'config.recipients' => ['nullable', 'string', 'in:members', 'required_without:config.addresses', 'prohibits:config.addresses'],
                'config.addresses' => ['nullable', 'array', 'min:1', 'max:'.self::MAX_ADDRESSES, 'required_without:config.recipients'],
                'config.addresses.*' => ['required', 'string', 'email:rfc', 'max:255', 'distinct:ignore_case'],
            ],
            NotificationChannelType::Slack => [
                'config' => ['required', 'array'],
                'config.url' => ['required', 'string', 'max:2048', self::safeUrl(SlackWebhookChannel::HOST)],
            ],
            NotificationChannelType::Webhook => [
                'config' => ['required', 'array'],
                'config.url' => ['required', 'string', 'max:2048', self::safeUrl(null)],
                'config.secret' => ['nullable', 'string', self::webhookSecret()],
            ],
        };
    }

    /**
     * The validated config with its defaults: a webhook without a secret gets
     * a freshly generated `whsec_` one.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function prepare(NotificationChannelType $type, array $config): array
    {
        $secret = $config['secret'] ?? null;

        if ($type === NotificationChannelType::Webhook && (! is_string($secret) || trim($secret) === '')) {
            $config['secret'] = StandardWebhookSigner::generateSecret();
        }

        return $config;
    }

    /**
     * @return Closure(string, mixed, Closure): void
     */
    private static function safeUrl(?string $requiredHost): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($requiredHost): void {
            if (! is_string($value)) {
                return; // the `string` rule reports it
            }

            try {
                SafeWebhookUrl::forEnvironment()->validate($value, $requiredHost);
            } catch (UnsafeWebhookUrl $refused) {
                $fail($refused->getMessage());
            }
        };
    }

    /**
     * @return Closure(string, mixed, Closure): void
     */
    private static function webhookSecret(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return; // the `string` rule reports it
            }

            if (! str_starts_with($value, StandardWebhookSigner::PREFIX)) {
                $fail('The signing secret must start with whsec_.');

                return;
            }

            try {
                new StandardWebhookSigner($value);
            } catch (InvalidArgumentException $invalid) {
                $fail($invalid->getMessage());
            }
        };
    }
}
