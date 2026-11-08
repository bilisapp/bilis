<?php

/*
 * Alerting is evaluated by querying ClickHouse on a schedule, never inside an
 * ingest request (specs/alerting.md, "Why not at the writers"). Nothing on the
 * ingest path may reach for an alerting class.
 */
arch('the ingest services never depend on alerting')
    ->expect('App\Services\Ingest')
    ->not->toUse(['App\Services\Alerting', 'App\Jobs\EvaluateAlertRule', 'App\Notifications']);

arch('the ingest controllers never depend on alerting')
    ->expect('App\Http\Controllers\Api')
    ->not->toUse(['App\Services\Alerting', 'App\Jobs\EvaluateAlertRule', 'App\Notifications']);
