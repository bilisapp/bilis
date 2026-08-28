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

    /*
    | Ayos is not a service. One fix job is one container run with no inbound
    | HTTP, so there is no URL to call and no shared secret to hold: Bilis
    | starts a run through a driver, injects a per-run Ed25519 key, and waits
    | to be called back.
    |
    | `local` spawns the runner as a child process on this machine — the whole
    | of local development. `scaleway` starts a Serverless Job run. The two
    | differ only in how a run is started and stopped; everything downstream is
    | identical, which is the point of a runner with no inbound surface.
    */
    'runner' => [
        'driver' => env('AUTOFIX_RUNNER_DRIVER', 'local'),

        'local' => [
            // The built entrypoint. `pnpm build` in the ayos repo produces it.
            'entrypoint' => env('AUTOFIX_RUNNER_ENTRYPOINT', base_path('../ayos/dist/src/entry.js')),
            'node' => env('AUTOFIX_RUNNER_NODE', 'node'),
            'log_path' => env('AUTOFIX_RUNNER_LOG_PATH', storage_path('logs/ayos-runs')),
        ],

        'scaleway' => [
            'api_url' => env('SCW_API_URL', 'https://api.scaleway.com'),
            'region' => env('SCW_REGION', 'fr-par'),
            'secret_key' => env('SCW_SECRET_KEY'),
            'job_definition_id' => env('AUTOFIX_SCW_JOB_DEFINITION_ID'),
        ],
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

    /*
    | Model credentials belong to teams: a team adds as many as it likes, one
    | per provider or several against the same one, and picks which a job runs
    | on. The key below is the exception rather than the rule — a single-tenant
    | or self-hosted deployment where "the customer" and "the operator" are the
    | same party, and there is nobody to paste a key into team settings. A
    | multi-tenant instance should leave it unset.
    */
    'llm' => [
        'api_key' => env('AUTOFIX_LLM_API_KEY', env('AUTOFIX_ANTHROPIC_API_KEY')),
        'provider' => env('AUTOFIX_LLM_PROVIDER', 'anthropic'),
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
