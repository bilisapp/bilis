<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Autofix
    |--------------------------------------------------------------------------
    |
    | The control plane for the auto-fixer. Bilis owns every credential here:
    | the GitHub App key, the Ayos shared secret, the stream signing key and
    | the LLM key. Ayos only ever receives short-lived, per-job material.
    |
    | The whole surface is off unless `enabled` is true, and each repository
    | opts in separately through `project_repositories.autofix_enabled`.
    |
    */

    'enabled' => env('AUTOFIX_ENABLED', false),

    'ayos' => [
        'url' => env('AUTOFIX_AYOS_URL'),
        'stream_url' => env('AUTOFIX_AYOS_STREAM_URL'),
        'shared_secret' => env('AUTOFIX_SHARED_SECRET'),
    ],

    /*
    | One GitHub App serves the whole product: its OAuth client credentials
    | power "Continue with GitHub" (config/services.php), and the keys below
    | power repository access — installation tokens and webhooks — once a
    | team installs the App to enable autofix.
    */
    'github' => [
        'app_id' => env('GITHUB_APP_ID'),
        'slug' => env('GITHUB_APP_SLUG'),
        'private_key' => env('GITHUB_APP_PRIVATE_KEY'),
        'webhook_secret' => env('GITHUB_APP_WEBHOOK_SECRET'),
    ],

    'stream_jwt' => [
        'private_key' => env('AUTOFIX_STREAM_PRIVATE_KEY'),
        'ttl_minutes' => 10,
    ],

    'llm' => [
        'api_key' => env('AUTOFIX_ANTHROPIC_API_KEY'),
    ],

    'defaults' => [
        'timeout_s' => 900,
        'max_diff_lines' => 800,
        'min_error_count' => 5,
        'cooldown_days' => 7,
        'verify_after_hours' => 2,
        'verify_fail_after_hours' => 24,
        'path_denylist' => ['.github/**', '.env*'],
    ],

];
