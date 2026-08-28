<?php

namespace App\Enums;

/**
 * A model provider a team can hold a credential for.
 *
 * The set is deliberately short and closed. Every value here is one the runner
 * already has a model catalogue for, so a provider a customer can pick is one
 * a job can actually run on — the picker never offers a choice the container
 * would fail on two minutes later.
 *
 * The `host` is the API hostname the credential is valid at. Bilis sends it
 * with the job spec rather than letting the runner infer it, because Bilis is
 * the party that minted (or was handed) the key and therefore the only one
 * that knows where it works.
 */
enum LlmProvider: string
{
    case Anthropic = 'anthropic';
    case OpenAi = 'openai';
    case OpenRouter = 'openrouter';

    /**
     * All enum values.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $provider): string => $provider->value, self::cases());
    }

    /**
     * Get the display label for the provider.
     */
    public function label(): string
    {
        return match ($this) {
            self::Anthropic => 'Anthropic',
            self::OpenAi => 'OpenAI',
            self::OpenRouter => 'OpenRouter',
        };
    }

    /**
     * The API hostname this provider's credentials authenticate against.
     */
    public function host(): string
    {
        return match ($this) {
            self::Anthropic => 'api.anthropic.com',
            self::OpenAi => 'api.openai.com',
            self::OpenRouter => 'openrouter.ai',
        };
    }

    /**
     * What a key for this provider looks like, for the settings field.
     *
     * A placeholder and nothing more: it is never validated against, because a
     * provider is free to change its key format and a customer locked out by
     * our guess about it has a worse problem than a typo.
     */
    public function keyPlaceholder(): string
    {
        return match ($this) {
            self::Anthropic => 'sk-ant-...',
            self::OpenAi => 'sk-...',
            self::OpenRouter => 'sk-or-v1-...',
        };
    }
}
