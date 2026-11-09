<?php

/*
 * Alerting is evaluated by querying ClickHouse on a schedule, never inside an
 * ingest request (specs/alerting.md, "Why not at the writers"). Nothing on the
 * ingest path may reach for an alerting class.
 */
/**
 * Everything that belongs to alerting: the engine, its jobs, its models and
 * its notifications.
 */
const ALERTING_CLASSES = [
    'App\Services\Alerting',
    'App\Jobs\EvaluateAlertRule',
    'App\Jobs\ScanIssueAlerts',
    'App\Jobs\ProvisionTeamAlerts',
    'App\Models\AlertRule',
    'App\Models\AlertInstance',
    'App\Models\AlertEvent',
    'App\Models\NotificationChannel',
    'App\Notifications',
];

arch('the ingest services never depend on alerting')
    ->expect('App\Services\Ingest')
    ->not->toUse(ALERTING_CLASSES);

arch('the ingest controllers never depend on alerting')
    ->expect('App\Http\Controllers\Api')
    ->not->toUse(ALERTING_CLASSES);
