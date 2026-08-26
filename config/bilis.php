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
    | Ingest
    |--------------------------------------------------------------------------
    |
    | `otlp_protobuf` decides whether `POST /api/v1/logs` accepts the protobuf
    | encoding as well as JSON. It is decoded in pure PHP by
    | App\Services\Ingest\Protobuf — no extension, no composer package — which
    | is why it has an off switch: turn it off and a protobuf export answers
    | 415 with the JSON hint again, exactly as it did before the decoder
    | existed. Nothing else changes; the JSON path never touches it.
    |
    | `max_decompressed_bytes` caps what a gzip or deflate body may expand to,
    | so a compression bomb cannot be traded for the process's memory. A body
    | that hits the cap is discarded rather than half-read.
    |
    */

    'ingest' => [

        'otlp_protobuf' => (bool) env('BILIS_OTLP_PROTOBUF', true),

        'max_decompressed_bytes' => (int) env('BILIS_INGEST_MAX_DECOMPRESSED_BYTES', 32 * 1024 * 1024),

    ],

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
