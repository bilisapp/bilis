<?php

/*
|--------------------------------------------------------------------------
| Hosted Plans
|--------------------------------------------------------------------------
|
| The allowances of the hosted Free plan on bilis.app. Every one of them is
| SOFT: nothing here is ever consulted by an ingest path, no request is
| rejected because of a number in this file, and no button is disabled by it.
| The app shows a team where it stands and says when it is over; that is the
| whole enforcement story, on purpose — dropping telemetry to make a point
| about a quota loses exactly the data someone is about to need.
|
| Two of the six published numbers are deliberately absent: retention comes
| from `legal.log_retention_days` (the privacy and terms pages promise it) and
| requests per minute from `security.ingest_rate_limit` (the limiter enforces
| it). Duplicating either here is how a published number goes stale against
| the behaviour it describes.
|
| A self-hosted install has no plan at all. Nothing reads these values unless
| a page or a card asks for them, and none of them bound what an instance you
| run yourself can do.
|
*/

return [

    'free' => [

        /**
         * Projects one team may create.
         */
        'projects_per_team' => (int) env('BILIS_PLAN_FREE_PROJECTS', 3),

        /**
         * People in one team, the owner included.
         */
        'members_per_team' => (int) env('BILIS_PLAN_FREE_MEMBERS', 5),

        /**
         * Log records plus spans accepted in one UTC day, across the team.
         */
        'events_per_day' => (int) env('BILIS_PLAN_FREE_EVENTS_PER_DAY', 100_000),

    ],

    /*
    |--------------------------------------------------------------------------
    | Warning Threshold
    |--------------------------------------------------------------------------
    |
    | The share of an allowance at which the dashboard starts saying so. Early
    | enough to be useful, late enough that a normal week is quiet.
    |
    */

    'warn_at_percent' => (int) env('BILIS_PLAN_WARN_AT_PERCENT', 80),

];
