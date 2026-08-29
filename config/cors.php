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
    | The DSN endpoints (`/api/{id}/envelope`, `/store`) are deliberately NOT
    | listed. A client that authenticates by URL carries a credential the page
    | it runs in discloses, so a wildcard would let any site on the internet
    | post with a key lifted from someone's page source. Those routes answer
    | from a per-project allow list instead, in `HandleEnvelopeCors`; leaving
    | them out here is what lets that middleware be the only voice on the
    | matter, rather than one of two writing the same header.
    |
    */

    'paths' => ['api/v1/*'],

    'allowed_methods' => ['POST', 'OPTIONS'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Bilis-Key'],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 3600,

    'supports_credentials' => false,

];
