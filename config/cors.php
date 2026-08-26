<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing
    |--------------------------------------------------------------------------
    |
    | Only the ingest endpoints are cross-origin, and deliberately so: a
    | browser-side OTLP exporter posts from whatever origin the customer's app
    | is served from, and Bilis cannot know that origin in advance.
    |
    | This is safe precisely because ingest is not cookie authenticated.
    | `supports_credentials` stays false, so no browser will ever attach a
    | Bilis session to a cross-origin request — authorisation is the API key in
    | the header and nothing else. Turning it on would make the wildcard origin
    | a CSRF hole, and browsers reject the combination anyway.
    |
    | Session-backed routes are not listed here at all, so they remain
    | same-origin and CSRF protected.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['POST', 'OPTIONS'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Bilis-Key'],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 3600,

    'supports_credentials' => false,

];
