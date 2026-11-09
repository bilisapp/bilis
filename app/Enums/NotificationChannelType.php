<?php

namespace App\Enums;

/**
 * How a notification channel delivers.
 */
enum NotificationChannelType: string
{
    case Email = 'email';
    case Slack = 'slack';
    case Webhook = 'webhook';

    /**
     * All enum values.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
