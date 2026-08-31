<?php

use Illuminate\Support\Str;
use Keepsuit\LaravelOpenTelemetry\Instrumentation;
use Keepsuit\LaravelOpenTelemetry\Support\ResourceAttributesParser;
use Keepsuit\LaravelOpenTelemetry\TailSampling;
use Keepsuit\LaravelOpenTelemetry\WorkerMode;
use OpenTelemetry\SDK\Common\Configuration\Variables;

return [
    /**
     * When set to true, Opentelemetry SDK will be disabled.
     *
     * The package ships opt-out, defaulting the exporter at localhost:4318 and
     * shouting into stderr on every request when nothing is listening there.
     * Bilis is self-hosted by people who mostly do not run a second collector,
     * so this is inverted: no `OTEL_EXPORTER_OTLP_ENDPOINT`, no SDK. Same
     * contract as the `bilis` log channel, which is inert until it is told
     * where to ship. Set `OTEL_SDK_DISABLED` explicitly to override either way.
     */
    'disabled' => filter_var(
        env(Variables::OTEL_SDK_DISABLED, env(Variables::OTEL_EXPORTER_OTLP_ENDPOINT) === null),
        FILTER_VALIDATE_BOOLEAN
    ),

    /**
     * Service name
     */
    'service_name' => env(Variables::OTEL_SERVICE_NAME, Str::slug((string) env('APP_NAME', 'laravel-app'))),

    /**
     * Service instance id
     * Should be unique for each instance of your service.
     * If not set, a random id will be generated on each request.
     */
    'service_instance_id' => env('OTEL_SERVICE_INSTANCE_ID'),

    /**
     * Additional resource attributes
     * Key-value pairs of resource attributes to add to all telemetry data.
     * By default, reads and parses OTEL_RESOURCE_ATTRIBUTES environment variable (which should be in the format 'key1=value1,key2=value2').
     */
    'resource_attributes' => ResourceAttributesParser::parse((string) env(Variables::OTEL_RESOURCE_ATTRIBUTES, '')),

    /**
     * Include authenticated user context on traces and logs.
     */
    'user_context' => filter_var(env('OTEL_USER_CONTEXT', true), FILTER_VALIDATE_BOOLEAN),

    /**
     * Comma separated list of propagators to use.
     * Supports any otel propagator, for example: "tracecontext", "baggage", "b3", "b3multi", "none"
     */
    'propagators' => env(Variables::OTEL_PROPAGATORS, 'tracecontext'),

    /**
     * OpenTelemetry Meter configuration
     */
    'metrics' => [
        /**
         * Metrics exporter
         * This should be the key of one of the exporters defined in the exporters section
         * Supported drivers: "otlp", "console", "memory", "null"
         *
         * Metrics are out of scope for Bilis and there is nowhere to put them,
         * so the default is `null` rather than the package's `otlp`. Left at
         * `otlp` the meter provider posts to /v1/metrics on shutdown, gets a
         * 404 from a Bilis that only serves logs and traces, and retries three
         * times before printing a stack trace — once per request.
         */
        'exporter' => env(Variables::OTEL_METRICS_EXPORTER, 'null'),
    ],

    /**
     * OpenTelemetry Traces configuration
     */
    'traces' => [
        /**
         * Traces exporter
         * This should be the key of one of the exporters defined in the exporters section
         * Supported drivers: "otlp", "zipkin", "console", "memory", "null"
         */
        'exporter' => env(Variables::OTEL_TRACES_EXPORTER, 'otlp'),

        /**
         * Traces sampler
         */
        'sampler' => [
            /**
             * Wraps the sampler in a parent based sampler
             */
            'parent' => filter_var(env('OTEL_TRACES_SAMPLER_PARENT', true), FILTER_VALIDATE_BOOLEAN),

            /**
             * Sampler type
             * Supported values: "always_on", "always_off", "traceidratio"
             */
            'type' => env('OTEL_TRACES_SAMPLER_TYPE', 'always_on'),

            'args' => [
                /**
                 * Sampling ratio for traceidratio sampler
                 */
                'ratio' => env('OTEL_TRACES_SAMPLER_TRACEIDRATIO_RATIO', 0.05),
            ],

            'tail_sampling' => [
                'enabled' => filter_var(env('OTEL_TRACES_TAIL_SAMPLING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
                // Maximum time to wait for the end of the trace before making a sampling decision (in milliseconds)
                'decision_wait' => (int) env('OTEL_TRACES_TAIL_SAMPLING_DECISION_WAIT', 5000),

                'rules' => [
                    TailSampling\Rules\ErrorsRule::class => filter_var(env('OTEL_TRACES_TAIL_SAMPLING_RULE_KEEP_ERRORS', true), FILTER_VALIDATE_BOOLEAN),
                    TailSampling\Rules\SlowTraceRule::class => [
                        'enabled' => filter_var(env('OTEL_TRACES_TAIL_SAMPLING_RULE_SLOW_TRACES', true), FILTER_VALIDATE_BOOLEAN),
                        'threshold_ms' => (int) env('OTEL_TRACES_TAIL_SAMPLING_SLOW_TRACES_THRESHOLD_MS', 2000),
                    ],
                ],
            ],
        ],

        /**
         * Traces span processors.
         * Processors classes must implement OpenTelemetry\SDK\Trace\SpanProcessorInterface
         *
         * Example: YourTracesSpanProcessor::class
         */
        'processors' => [],
    ],

    /**
     * OpenTelemetry logs configuration
     */
    'logs' => [
        /**
         * Logs exporter
         * This should be the key of one of the exporters defined in the exporters section
         * Supported drivers: "otlp", "console", "memory", "null"
         *
         * Logs already leave this application through the `bilis` Monolog
         * channel. Exporting them over OTLP as well would store every line
         * twice under two different service names.
         */
        'exporter' => env(Variables::OTEL_LOGS_EXPORTER, 'null'),

        /**
         * Inject active trace id in log context
         *
         * When using the OpenTelemetry logger, the trace id is always injected in the exported log record.
         * This option allows to inject the trace id in the log context for other loggers.
         *
         * Off here because it shares only the trace id, and via
         * `Log::shareContext()`, which is global and outlives the span. The
         * `bilis` channel taps `App\Logging\AddTraceContext` instead: it
         * stamps trace id *and* span id per record, from the span that was
         * current when the line was written, which is what the log row's
         * "view span" link needs.
         */
        'inject_trace_id' => false,

        /**
         * Context field name for trace id
         */
        'trace_id_field' => 'trace_id',

        /**
         * Logs record processors.
         * Processors classes must implement OpenTelemetry\SDK\Logs\LogRecordProcessorInterface
         *
         * Example: YourLogRecordProcessor::class
         */
        'processors' => [],
    ],

    /**
     * OpenTelemetry exporters
     *
     * Here you can configure exports used by metrics, traces and logs.
     * If you want to use the same protocol with different endpoints,
     * you can copy the exporter with a different and change the endpoint
     *
     * Supported drivers: "otlp", "zipkin" (only traces), "console", "memory", "null"
     */
    'exporters' => [
        'otlp' => [
            'driver' => 'otlp',
            'endpoint' => env(Variables::OTEL_EXPORTER_OTLP_ENDPOINT, 'http://localhost:4318'),
            /**
             * Supported protocols: "grpc", "http/protobuf", "http/json"
             */
            'protocol' => env(Variables::OTEL_EXPORTER_OTLP_PROTOCOL, 'http/protobuf'),
            /*
             * Both of these are lower than the package's 3 retries and 10s,
             * and the reason is the shape of exporting to yourself.
             *
             * The export runs synchronously at the end of a request, and the
             * endpoint it posts to is served by the same PHP worker pool. So a
             * request does not release its worker until a *second* worker has
             * answered the export — every traced request occupies two. When the
             * pool is small and something is slow, the workers spend their time
             * waiting on each other, the pool exhausts, and the symptom is a
             * 502 on an application that has no bug in it. 3 retries at 10s is
             * 30 seconds of one worker held by a sink that is already failing.
             *
             * This is the same judgement `BilisHandler` makes with its 2s
             * timeout and its dropped batch: telemetry that cannot be delivered
             * quickly is discarded, never queued behind the request. If you can
             * put a Collector between the application and Bilis, do — then the
             * hop that has to be fast is a local one.
             */
            'max_retries' => (int) env('OTEL_EXPORTER_OTLP_MAX_RETRIES', 1),
            'traces_timeout' => (int) env(Variables::OTEL_EXPORTER_OTLP_TRACES_TIMEOUT, env(Variables::OTEL_EXPORTER_OTLP_TIMEOUT, 3000)),
            'traces_headers' => (string) env(Variables::OTEL_EXPORTER_OTLP_TRACES_HEADERS, env(Variables::OTEL_EXPORTER_OTLP_HEADERS, '')),
            /**
             * Override protocol for traces export
             */
            'traces_protocol' => env(Variables::OTEL_EXPORTER_OTLP_TRACES_PROTOCOL),
            'metrics_timeout' => (int) env(Variables::OTEL_EXPORTER_OTLP_METRICS_TIMEOUT, env(Variables::OTEL_EXPORTER_OTLP_TIMEOUT, 10000)),
            'metrics_headers' => (string) env(Variables::OTEL_EXPORTER_OTLP_METRICS_HEADERS, env(Variables::OTEL_EXPORTER_OTLP_HEADERS, '')),
            /**
             * Override protocol for metrics export
             */
            'metrics_protocol' => env(Variables::OTEL_EXPORTER_OTLP_METRICS_PROTOCOL),
            /**
             * Preferred metrics temporality
             * Supported values: "Delta", "Cumulative"
             */
            'metrics_temporality' => env(Variables::OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE),
            'logs_timeout' => (int) env(Variables::OTEL_EXPORTER_OTLP_LOGS_TIMEOUT, env(Variables::OTEL_EXPORTER_OTLP_TIMEOUT, 10000)),
            'logs_headers' => (string) env(Variables::OTEL_EXPORTER_OTLP_LOGS_HEADERS, env(Variables::OTEL_EXPORTER_OTLP_HEADERS, '')),
            /**
             * Override protocol for logs export
             */
            'logs_protocol' => env(Variables::OTEL_EXPORTER_OTLP_LOGS_PROTOCOL),
        ],

        'zipkin' => [
            'driver' => 'zipkin',
            'endpoint' => env(Variables::OTEL_EXPORTER_ZIPKIN_ENDPOINT, 'http://localhost:9411'),
            'timeout' => env(Variables::OTEL_EXPORTER_ZIPKIN_TIMEOUT, 10000),
            'max_retries' => (int) env('OTEL_EXPORTER_ZIPKIN_MAX_RETRIES', 3),
        ],
    ],

    /**
     * List of instrumentation used for application tracing
     */
    'instrumentation' => [
        Instrumentation\HttpServerInstrumentation::class => [
            'enabled' => filter_var(env('OTEL_INSTRUMENTATION_HTTP_SERVER', true), FILTER_VALIDATE_BOOLEAN),

            /*
             * Laravel `$request->is()` patterns, matched against the path.
             *
             * `api/v1/traces` is not a preference. When Bilis exports its own
             * spans to itself, the export is an inbound POST like any other:
             * tracing it produces spans, which are exported, which produce
             * spans. The loop does not converge and does not need traffic to
             * start — one request seeds it. Every other entry here is volume
             * rather than recursion: the ingest routes are the hot path of the
             * product and would drown the trace list in copies of themselves,
             * and `up` is the container healthcheck firing forever whether
             * anyone is using the application or not.
             *
             * `{dsnProjectId}/envelope` and `/store` are the wire protocol's
             * own paths, so they are matched by shape rather than by prefix.
             */
            'excluded_paths' => [
                'api/v1/traces',
                'api/v1/logs',
                'api/v1/ingest',
                '*/envelope',
                '*/store',
                'up',
            ],
            'excluded_methods' => [],

            /*
             * No request headers are recorded. If you add any, note that the
             * ingest key arrives in one of these two and neither should ever
             * reach a span attribute.
             */
            'allowed_headers' => [],
            'sensitive_headers' => [
                'authorization',
                'x-bilis-key',
            ],
            'sensitive_query_parameters' => [],
        ],

        Instrumentation\HttpClientInstrumentation::class => [
            'enabled' => filter_var(env('OTEL_INSTRUMENTATION_HTTP_CLIENT', true), FILTER_VALIDATE_BOOLEAN),
            'manual' => false, // When set to true, you need to call `withTrace()` on the request to enable tracing
            'allowed_headers' => [],
            'sensitive_headers' => [],
            'sensitive_query_parameters' => [],
        ],

        Instrumentation\QueryInstrumentation::class => filter_var(env('OTEL_INSTRUMENTATION_QUERY', true), FILTER_VALIDATE_BOOLEAN),

        Instrumentation\RedisInstrumentation::class => filter_var(env('OTEL_INSTRUMENTATION_REDIS', true), FILTER_VALIDATE_BOOLEAN),

        Instrumentation\QueueInstrumentation::class => filter_var(env('OTEL_INSTRUMENTATION_QUEUE', true), FILTER_VALIDATE_BOOLEAN),

        Instrumentation\CacheInstrumentation::class => filter_var(env('OTEL_INSTRUMENTATION_CACHE', true), FILTER_VALIDATE_BOOLEAN),

        Instrumentation\EventInstrumentation::class => [
            'enabled' => filter_var(env('OTEL_INSTRUMENTATION_EVENT', true), FILTER_VALIDATE_BOOLEAN),
            'excluded' => [],
        ],

        Instrumentation\ViewInstrumentation::class => filter_var(env('OTEL_INSTRUMENTATION_VIEW', true), FILTER_VALIDATE_BOOLEAN),

        Instrumentation\LivewireInstrumentation::class => filter_var(env('OTEL_INSTRUMENTATION_LIVEWIRE', true), FILTER_VALIDATE_BOOLEAN),

        Instrumentation\ConsoleInstrumentation::class => [
            'enabled' => filter_var(env('OTEL_INSTRUMENTATION_CONSOLE', true), FILTER_VALIDATE_BOOLEAN),

            /*
             * A whitelist, not an exclusion list — `enabled` with an empty
             * `commands` traces nothing at all, which reads like the
             * instrumentation is broken.
             *
             * Only the commands that do work worth timing are named. The
             * scheduler runs `autofix:scan` every minute and `clickhouse:migrate`
             * runs on every container boot in three roles at once, so both are
             * places where a regression shows up as a schedule that silently
             * stops keeping up rather than as an error.
             */
            'commands' => [
                'autofix:scan',
                'autofix:verify',
                'clickhouse:migrate',
                'clickhouse:materialize-index',
            ],
        ],

        Instrumentation\ScoutInstrumentation::class => filter_var(env('OTEL_INSTRUMENTATION_SCOUT', true), FILTER_VALIDATE_BOOLEAN),
    ],

    /**
     * Worker mode detection configuration
     *
     * Detects worker modes (e.g., Octane, Horizon, Queue) and optimizes OpenTelemetry
     * behavior for long-running processes.
     */
    'worker_mode' => [
        /**
         * Flush after each iteration (e.g. http request, queue job).
         * If false, flushes are batched and executed periodically and on shutdown.
         */
        'flush_after_each_iteration' => filter_var(env('OTEL_WORKER_MODE_FLUSH_AFTER_EACH_ITERATION', false), FILTER_VALIDATE_BOOLEAN),

        /**
         * Metrics collection interval in seconds.
         * When running in worker mode, metrics are collected and exported at this interval.
         * Note: This setting is ignored if 'flush_after_each_iteration' is true.
         * Note: The interval is checked after each iteration, so the actual interval may be longer
         */
        'metrics_collect_interval' => (int) env('OTEL_WORKER_MODE_COLLECT_INTERVAL', 60),

        /**
         * Detectors to use for worker mode detection
         *
         * Detectors are checked in order, the first one that returns true determines the mode.
         * Custom detectors implementing DetectorInterface can be added here.
         *
         * Built-in detectors:
         * - OctaneDetector: Detects Laravel Octane
         * - QueueDetector: Detects Laravel default queue worker and Laravel Horizon
         */
        'detectors' => [
            WorkerMode\Detectors\OctaneWorkerModeDetector::class,
            WorkerMode\Detectors\QueueWorkerModeDetector::class,
        ],
    ],
];
