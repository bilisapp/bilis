<?php

namespace App\Enums;

/**
 * What an `alert_events` row records. `Firing` and `Resolved` are the only
 * transitions the state machine emits; the rest come from elsewhere.
 */
enum AlertEventType: string
{
    case Firing = 'firing';
    case Resolved = 'resolved';
    case IssueNew = 'issue_new';
    case IssueRegressed = 'issue_regressed';
    case RuleError = 'rule_error';
}
