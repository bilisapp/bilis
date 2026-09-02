<?php

namespace App\Enums;

enum ContactTopic: string
{
    case General = 'general';
    case Upgrade = 'upgrade';
    case Support = 'support';

    /**
     * The label the form's select and the notification subject use.
     */
    public function label(): string
    {
        return match ($this) {
            self::General => 'General question',
            self::Upgrade => 'More than the Free plan',
            self::Support => 'Something is broken',
        };
    }

    /**
     * Resolve a `?topic=` query value, falling back to a general enquiry.
     *
     * Deliberately forgiving: the parameter is a link's suggestion about what
     * the visitor came to talk about, not input to validate. An unknown value
     * is a stale link, and a stale link should still open a usable form.
     */
    public static function fromQuery(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::General;
    }
}
