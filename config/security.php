<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | Bilis renders a nonce based policy: every script Bilis itself emits
    | carries a request scoped nonce, and `strict-dynamic` lets those trusted
    | scripts pull their own chunks. Inline styles stay allowed because Vue
    | writes `style` attributes for positioning; scripts do not get that.
    |
    | Turn `report_only` on to watch a policy before it can break a page, and
    | use the `allow` lists to name extra origins a self-hosted instance needs
    | (an analytics host, an object store serving avatars, and so on).
    |
    */

    'csp' => [

        'enabled' => (bool) env('SECURITY_CSP_ENABLED', true),

        'report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', false),

        'report_uri' => env('SECURITY_CSP_REPORT_URI'),

        'allow' => [
            'script' => env('SECURITY_CSP_SCRIPT_SRC', ''),
            'style' => env('SECURITY_CSP_STYLE_SRC', ''),
            'img' => env('SECURITY_CSP_IMG_SRC', ''),
            'font' => env('SECURITY_CSP_FONT_SRC', ''),
            'connect' => env('SECURITY_CSP_CONNECT_SRC', ''),
            'frame' => env('SECURITY_CSP_FRAME_SRC', ''),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Strict Transport Security
    |--------------------------------------------------------------------------
    |
    | Only ever sent over a secure request, so a plain HTTP instance on a local
    | network cannot lock itself out of its own browser. `preload` is opt-in:
    | submitting a domain to the preload list is close to irreversible.
    |
    */

    'hsts' => [

        'enabled' => (bool) env('SECURITY_HSTS_ENABLED', true),

        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),

        'include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),

        'preload' => (bool) env('SECURITY_HSTS_PRELOAD', false),

    ],

    /*
    |--------------------------------------------------------------------------
    | Referrer and Permissions Policy
    |--------------------------------------------------------------------------
    |
    | Every powerful browser feature is denied except the two the app actually
    | uses: fullscreen, and the credential APIs behind passkey sign-in.
    |
    */

    'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),

    'permissions_policy' => env('SECURITY_PERMISSIONS_POLICY', implode(', ', [
        'accelerometer=()',
        'autoplay=()',
        'browsing-topics=()',
        'camera=()',
        'display-capture=()',
        'encrypted-media=()',
        'fullscreen=(self)',
        'geolocation=()',
        'gyroscope=()',
        'magnetometer=()',
        'microphone=()',
        'midi=()',
        'payment=()',
        'picture-in-picture=()',
        'publickey-credentials-create=(self)',
        'publickey-credentials-get=(self)',
        'screen-wake-lock=()',
        'serial=()',
        'usb=()',
        'xr-spatial-tracking=()',
    ])),

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Which addresses may set the X-Forwarded-* headers Bilis believes. The
    | default trusts everything, which is right when nothing but the reverse
    | proxy can reach the container. If the app port is reachable from
    | anywhere else, an unfiltered header is a client-controlled IP address:
    | name the proxies instead, comma separated, CIDR allowed.
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES', '*'),

    /*
    |--------------------------------------------------------------------------
    | Ingest Rate Limit
    |--------------------------------------------------------------------------
    |
    | Requests per minute per API key on the ingest endpoints. A rejection is a
    | 429 with `Retry-After`, which every OTLP exporter already treats as
    | retryable. Set to 0 to disable the limiter entirely.
    |
    | This counts requests, not records: a batching exporter sending 5k records
    | per POST costs one request. Instances doing real volume should point
    | `CACHE_STORE` at Redis so the counter is not a database write per POST.
    |
    */

    'ingest_rate_limit' => (int) env('BILIS_INGEST_RATE_LIMIT', 1200),

    'ingest_rate_limit_unauthenticated' => (int) env('BILIS_INGEST_RATE_LIMIT_UNAUTHENTICATED', 60),

];
