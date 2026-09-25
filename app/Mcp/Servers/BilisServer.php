<?php

namespace App\Mcp\Servers;

use App\Mcp\Prompts\DebugWithBilisPrompt;
use App\Mcp\Prompts\InstrumentWithBilisPrompt;
use App\Mcp\Tools\ErrorSummaryTool;
use App\Mcp\Tools\GetTraceTool;
use App\Mcp\Tools\ListMetricsTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListServicesTool;
use App\Mcp\Tools\ListTeamsTool;
use App\Mcp\Tools\ListTracesTool;
use App\Mcp\Tools\QueryMetricTool;
use App\Mcp\Tools\SearchLogsTool;
use App\Mcp\Tools\ServiceLatencyTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Tool;

#[Name('bilis')]
#[Version('1.0.0')]
#[Instructions(<<<'INSTRUCTIONS'
    Bilis is a log, trace and metric store: an application ships its logs, spans
    and metrics here over OTLP/HTTP, and this server lets you read them back.
    When someone asks why a request failed, what broke last night, what is slow
    or what is running hot, the answer is in here rather than in their terminal.

    How the data is shaped (it is probably not in your training data):
    - A **team** owns **projects**; a project is one application's logs,
      traces and metrics. Every tool takes an optional `team` slug (defaults to
      the user's current team) and an optional `project` slug (defaults to every
      project in the team). Call list-projects when you do not know which project to read.
    - **Logs** are OTel log records: a timestamp, a severity
      (trace/debug/info/warn/error/fatal), a service name, a body, and
      attributes. **Traces** are OTel spans grouped by a 32-hex trace id; a span
      id is 16 hex.
    - Logs and traces are linked both ways: a log line carries the `traceId` and
      `spanId` of the request it happened inside. That is the strongest move you
      have — find the failing log line with search-logs or error-summary, then
      pass its `traceId` to get-trace to see the whole request as a waterfall.
    - **Metrics** are OTel metrics: gauges, counters (sums), histograms and
      summaries, each with attributes. list-metrics names them and says how each
      is read — a cumulative counter comes back as a per-second rate, a
      histogram as p50/p95/p99 — and, given a `metric`, lists its attribute
      keys. query-metric returns one metric as a time series (optionally split
      by `group_by` and narrowed by `where`), each series with min/max/avg/last.
      Metrics answer "is it saturated, climbing, slower than usual?"; logs and
      traces answer "which request, and why?".

    Reading efficiently:
    - Every read is a time window, and it defaults to the last hour (list-metrics
      defaults to the last day, and metric windows stop at 30 days). Pass `from`
      and `to` (ISO-8601) when you need something older, and keep the window as
      narrow as the question — a wide window over a busy project returns a lot
      of rows and answers no better.
    - Start broad and narrow: list-projects, then error-summary or list-traces
      to find a candidate, then search-logs or get-trace on it.
    - Is something slow or saturated overall? service-latency for spans, or
      list-metrics then query-metric for what the app measures itself (queue
      depth, memory, request rate). Metric names must be exact — take them from
      list-metrics, never guess.
    - A result carrying `"unavailable": true` means the store did not answer,
      not that there was nothing to find. Say so rather than reporting silence.

    What this server deliberately cannot do:
    - It is **read-only**. It never sends logs, spans or metrics, never deletes
      anything, never creates a project, and never mints or reveals an API key. Wiring an
      app up to Bilis and managing keys happen in the Bilis web app; the
      instrument-with-bilis prompt explains the integration if you are asked to
      build one.
    - It never starts an Autofix job. That stays a deliberate click by a person.
    INSTRUCTIONS)]
class BilisServer extends Server
{
    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ListTeamsTool::class,
        ListProjectsTool::class,
        ListServicesTool::class,
        SearchLogsTool::class,
        ErrorSummaryTool::class,
        ListTracesTool::class,
        GetTraceTool::class,
        ServiceLatencyTool::class,
        ListMetricsTool::class,
        QueryMetricTool::class,
    ];

    /**
     * The resources registered with this MCP server.
     *
     * @var array<int, class-string<Server\Resource>>
     */
    protected array $resources = [
        //
    ];

    /**
     * The prompts registered with this MCP server.
     *
     * @var array<int, class-string<Prompt>>
     */
    protected array $prompts = [
        InstrumentWithBilisPrompt::class,
        DebugWithBilisPrompt::class,
    ];
}
