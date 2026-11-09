<?php

use App\Services\Alerting\Sources\LogAlertSource;
use App\Services\Alerting\Sources\MetricAlertSource;
use App\Services\Alerting\Sources\TraceAlertSource;

return [

    /*
    |--------------------------------------------------------------------------
    | Alerting
    |--------------------------------------------------------------------------
    |
    | Threshold rules over logs, traces and metrics, evaluated on a schedule
    | against ClickHouse — never at ingest. See specs/alerting.md.
    |
    | `lag` (seconds) is subtracted from now before aligning the evaluation
    | timestamp, so client-side batching has landed before a window is read.
    | `max_instances` caps the series one rule keeps (top N by value).
    | `retire_after` (seconds): a series firing only on no data for this long
    | is deleted silently, so a decommissioned host does not fire forever.
    | `sources` maps a rule's `source` key to its AlertSource class.
    | `provisioning`: install the default rules of config/alerting_templates.php
    | and a "Team email" channel into every team (`alerts:provision`,
    | specs/alerting-defaults.md). Off leaves teams with what they built.
    |
    */

    'enabled' => (bool) env('BILIS_ALERTING_ENABLED', true),

    'provisioning' => (bool) env('BILIS_ALERTING_PROVISIONING', true),

    'lag' => (int) env('BILIS_ALERTING_LAG', 60),

    'max_instances' => 100,

    'retire_after' => (int) env('BILIS_ALERTING_RETIRE_AFTER', 86_400),

    'sources' => [
        'logs' => LogAlertSource::class,
        'traces' => TraceAlertSource::class,
        'metrics' => MetricAlertSource::class,
    ],

    'events_retention_days' => 90,

];
