---
title: Claude Code
description: Point Claude Code's built-in OpenTelemetry exporter at Bilis, and the three defaults that otherwise send nothing.
order: 10
---

Claude Code ships an OpenTelemetry exporter. It is off by default, and when you
turn it on it emits all three OTel signals — logs, traces and metrics. Bilis
stores the first two, so this is a configuration change and nothing more: no
Collector, no wrapper, no plugin.

What you get out of it is a record of how the agent is actually being used —
tokens, cost and latency per request, which tools it reached for, how long a
turn took — sitting in the same viewer as the logs of the application it is
editing.

## 1. Configure it

Put this in `~/.claude/settings.json`. The `env` block is applied to every
Claude Code session on the machine:

```json
{
    "env": {
        "CLAUDE_CODE_ENABLE_TELEMETRY": "1",
        "CLAUDE_CODE_ENHANCED_TELEMETRY_BETA": "1",
        "OTEL_LOGS_EXPORTER": "otlp",
        "OTEL_TRACES_EXPORTER": "otlp",
        "OTEL_METRICS_EXPORTER": "none",
        "OTEL_EXPORTER_OTLP_PROTOCOL": "http/protobuf",
        "OTEL_EXPORTER_OTLP_LOGS_ENDPOINT": "https://bilis.example.com/api/v1/logs",
        "OTEL_EXPORTER_OTLP_TRACES_ENDPOINT": "https://bilis.example.com/api/v1/traces",
        "OTEL_EXPORTER_OTLP_HEADERS": "Authorization=Bearer bilis_YOUR_API_KEY",
        "OTEL_RESOURCE_ATTRIBUTES": "service.name=claude-code"
    }
}
```

**Restart Claude Code afterwards.** The exporter is built once at startup, so a
running session keeps whatever configuration it booted with.

The same thing as environment variables, if you would rather not write a key
into a settings file — see [below](#keeping-the-key-out-of-the-settings-file):

```bash
export CLAUDE_CODE_ENABLE_TELEMETRY=1
export CLAUDE_CODE_ENHANCED_TELEMETRY_BETA=1
export OTEL_LOGS_EXPORTER=otlp
export OTEL_TRACES_EXPORTER=otlp
export OTEL_METRICS_EXPORTER=none
export OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
export OTEL_EXPORTER_OTLP_LOGS_ENDPOINT=https://bilis.example.com/api/v1/logs
export OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=https://bilis.example.com/api/v1/traces
export OTEL_EXPORTER_OTLP_HEADERS="Authorization=Bearer bilis_YOUR_API_KEY"
```

### The three defaults that send nothing

Every line above earns its place. Three of them are the difference between
working and a silence with no error in it:

- **`http/protobuf`, because the default is gRPC.** Left alone, the exporter
  dials OTLP over gRPC on port 4317. Bilis speaks OTLP over HTTP only and has
  nothing listening there. This is the same trap described in
  [Traces](/docs/ingestion/traces), and it is the most common reason a new
  install looks broken.
- **The per-signal endpoints, because Bilis serves `/api/v1/…`.** Given the
  signal-agnostic `OTEL_EXPORTER_OTLP_ENDPOINT`, the exporter appends `/v1/logs`
  and `/v1/traces` itself and misses by a path segment.
  `OTEL_EXPORTER_OTLP_LOGS_ENDPOINT` and `OTEL_EXPORTER_OTLP_TRACES_ENDPOINT`
  are used **verbatim**, which is why they name the full path.
- **`OTEL_METRICS_EXPORTER=none`, because Bilis has no metrics.** Metrics are
  out of scope on purpose and there is no endpoint to receive them. Left at
  `otlp`, the exporter POSTs a metrics payload to a URL that does not exist,
  once a minute, forever, and fails quietly in the background.

Traces sit behind `CLAUDE_CODE_ENHANCED_TELEMETRY_BETA=1`. Without it you still
get the logs, which is where the token and cost numbers live anyway.

That configuration records what the agent _did_, not what it was _told_: prompt
and tool content is redacted until you ask for it. See
[Recording prompts and tool content](#recording-prompts-and-tool-content) for
the four switches that change that, and the one that turns on more than it
looks like.

## 2. What arrives

**Logs.** One record per event, with the event name as the message body:

| Body                             | What it marks                   |
| -------------------------------- | ------------------------------- |
| `claude_code.user_prompt`        | A prompt was submitted          |
| `claude_code.api_request`        | One request to the model        |
| `claude_code.assistant_response` | One response came back          |
| `claude_code.tool_decision`      | A tool was accepted or rejected |
| `claude_code.tool_result`        | A tool finished                 |
| `claude_code.plugin_loaded`      | A plugin was loaded at startup  |

The numbers are attributes rather than text. A `claude_code.api_request` record
carries `model`, `input_tokens`, `output_tokens`, `cache_read_tokens`,
`cache_creation_tokens`, `cost_usd`, `duration_ms` and `request_id`, alongside
`session.id`, `terminal.type` and the account identifiers.

**Traces.** A `claude_code.interaction` span per turn, with a
`claude_code.llm_request` child per model call and a `claude_code.tool` child
per tool the agent reached for — so a slow turn shows you whether the time went
into the model or into the tools. A tool that waited on you for approval says so
in its own right, as a `claude_code.tool.blocked_on_user` child, which is the
difference between "the agent is slow" and "the agent was waiting for me".

> **These records have no severity.** Claude Code sends them without a severity
> number or text, so they land unclassified and a severity filter will not
> find them. Filter by service (`claude-code`) instead. See
> [Severity](/docs/ingestion/severity).

## 3. What it sends about you

Worth knowing before you point this at a shared instance, because none of it is
obvious from the configuration:

- **Prompt and response text is redacted by default.** The
  `claude_code.interaction` span carries a `user_prompt` attribute whose value
  is the literal string `<REDACTED>` — but `user_prompt_length` is a real
  number, so the _shape_ of your session is exported even when the content is
  not.
- **Your account is identified.** `user.email`, `user.id`,
  `user.account_uuid` and `organization.id` ride on every record. On a shared
  Bilis instance, everyone who can read that project can see whose session it
  was.
- **Content capture is opt-in, per kind.** Four switches turn the redaction off
  again; they are covered in full [below](#recording-prompts-and-tool-content).

Use a project of its own — `claude-code`, not the project your application logs
into — and the retention and access questions answer themselves.

### Recording prompts and tool content

If what you want is a record of what the agent was actually asked to do — for
reviewing your own sessions, or debugging an agent that went sideways — turn the
redaction off deliberately, one kind of content at a time:

| Variable                         | What it stops redacting                                                                      |
| -------------------------------- | -------------------------------------------------------------------------------------------- |
| `OTEL_LOG_USER_PROMPTS=1`        | The real prompt text in `user_prompt`, on the span and the log record                        |
| `OTEL_LOG_ASSISTANT_RESPONSES=1` | The model's replies                                                                          |
| `OTEL_LOG_TOOL_DETAILS=1`        | `file_path`, `full_command`, `skill_name` and `subagent_type` on the `claude_code.tool` span |
| `OTEL_LOG_TOOL_CONTENT=1`        | A `tool.output` span **event** on that span, carrying the tool's input and output bodies     |

In Bilis the tool bodies land in the span's `Events.*` columns rather than its
attributes, so a bash call arrives as a `tool.output` event with `bash_command`
and `output` beside it. Each attribute is truncated at roughly 60 KB, which
`CLAUDE_CODE_OTEL_CONTENT_MAX_LENGTH` moves.

Two of these have sharp edges, and both are the kind you find out about
afterwards:

- **`OTEL_LOG_ASSISTANT_RESPONSES` defaults to whatever `OTEL_LOG_USER_PROMPTS`
  is.** It is read as `OTEL_LOG_ASSISTANT_RESPONSES ?? OTEL_LOG_USER_PROMPTS`,
  so turning on prompt capture turns on the model's replies as well, silently.
  If you want the questions but not the answers, set it to `0` **explicitly** —
  leaving it out is not the same thing.
- **`OTEL_LOG_RAW_API_BODIES` is a different order of magnitude.** It writes the
  whole Messages API request and response, which includes the entire
  conversation history on every call — not one prompt, all of them, again and
  again. It is a debugging tool, not a setting to leave on.

Whatever you enable, remember what these fields are: prompts and tool output are
where source code, customer data and the occasional credential turn up. On a
self-hosted Bilis that content stays on your own box, which is rather the point
— but it is still worth deciding on the retention and on who can read the
project, rather than discovering the answer later.

> **Not this:** `ENABLE_BETA_TRACING_DETAILED` also exists, and adds
> content-bearing attributes like `system_prompt_preview` and
> `response.model_output`. It is gated on a separate `BETA_TRACING_ENDPOINT` and
> sends there rather than to your OTLP endpoint, so it does nothing for a Bilis
> setup. It is not part of the stable schema either — leave it alone.

### Keeping the key out of the settings file

`settings.json` stores the key in plaintext, and it is a file people copy
between machines and occasionally commit. Two ways out, both fine:

- Export the variables from your shell profile instead of the `env` block. They
  are read the same way.
- Set `otelHeadersHelper` in `settings.json` to a script that prints the header,
  and keep the key in your password manager or keychain.

## 4. Check that it worked

Restart Claude Code, send one prompt, then look for the service in the viewer:

```
service:claude-code
```

Records appear within a few seconds — logs are flushed every 5 seconds by
default (`OTEL_LOGS_EXPORT_INTERVAL`), so wait one interval before deciding it
is broken. Nothing at all almost always means one of the three defaults above.

To watch it from the terminal instead of the UI, point the exporter at your
console for one session:

```bash
CLAUDE_CODE_ENABLE_TELEMETRY=1 OTEL_LOGS_EXPORTER=console claude -p "hello"
```

If records print there but never reach Bilis, the problem is the endpoint,
the protocol or the key — not the instrumentation.

## Other agents

Claude Code is the one with an OpenTelemetry exporter built in. Other coding
agents mostly do not have one, and until they do, the way in is the same as for
any other program that is not OTel-aware: the
[plain JSON endpoint](/docs/ingestion/endpoints), from whatever hook or
extension point the tool offers.
