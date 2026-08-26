<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public Links
    |--------------------------------------------------------------------------
    |
    | Links the public marketing pages point at. These describe the Bilis
    | project itself rather than any one instance, but they stay overridable
    | so a fork can point its own landing page somewhere else.
    |
    */

    'github_url' => env('BILIS_GITHUB_URL', 'https://github.com/bilisapp/bilis'),

    /*
    |--------------------------------------------------------------------------
    | Analytics
    |--------------------------------------------------------------------------
    |
    | An optional privacy-preserving analytics beacon on the public pages and
    | the app shell. The script origin is fed to the Content Security Policy
    | automatically, so pointing this at another Umami instance is enough —
    | leave the URL empty and no script is emitted and no origin is allowed.
    |
    */

    'analytics' => [

        'script_url' => env('BILIS_ANALYTICS_SCRIPT_URL', 'https://umami.lsd.sk/script.js'),

        'website_id' => env('BILIS_ANALYTICS_WEBSITE_ID', 'a44fb3bb-e339-4c3e-aa58-997ea902e51e'),

    ],

];
