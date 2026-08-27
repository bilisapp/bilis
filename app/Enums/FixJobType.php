<?php

namespace App\Enums;

/**
 * What raised a fix job, and therefore what the agent is being asked to do.
 *
 * `error` is the scheduled path: `FixTriggerService` saw a fingerprint recur
 * often enough to be worth an attempt, and the job carries the error context
 * that proves it. `custom` is a person: a team member typed instructions
 * against a connected repository, so there is no fingerprint and no error
 * context — only the request itself.
 *
 * Everything downstream of dispatch is identical for both. A custom job cannot
 * do anything an error job cannot: same diff validation, same denylist, same
 * pull request path.
 */
enum FixJobType: string
{
    case Error = 'error';
    case Custom = 'custom';

    /**
     * All enum values.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }

    /**
     * Get the display label for the type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Error => 'Error',
            self::Custom => 'Custom',
        };
    }

    /**
     * Determine whether the job was raised from a production error.
     */
    public function isError(): bool
    {
        return $this === self::Error;
    }

    /**
     * Determine whether the job was spawned by a person with instructions.
     */
    public function isCustom(): bool
    {
        return $this === self::Custom;
    }
}
