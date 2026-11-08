<?php

namespace App\Enums;

/**
 * Whether a rule's last evaluation ran (Prometheus' rule `health`). A query
 * error marks the rule `Err` and changes no instance.
 */
enum AlertRuleHealth: string
{
    case Unknown = 'unknown';
    case Ok = 'ok';
    case Err = 'err';
}
