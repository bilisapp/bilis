---
title: MCP server
description: Connect Claude, ChatGPT, Gemini CLI, Cursor or any MCP client to Bilis in one line, and let your agent read your logs, traces and metrics while it works.
order: 2
---

Bilis speaks the [Model Context Protocol](https://modelcontextprotocol.io). Point
an AI client at your instance and it can search your logs, open a trace as a
waterfall, chart a metric, and tell you which service is slow — while it is working in the
codebase that produced them.

There is no API key to copy. The server authenticates over OAuth: the first time
your client connects, a browser opens, you sign in as yourself and approve the
connection on a consent screen. The client registers itself.

## Connect Claude Code

One line in your terminal:

```bash
claude mcp add --transport http bilis https://bilis.example.com/mcp
```

The next time you talk to Claude it walks you through signing in, and the Bilis
tools are there.

## Connect ChatGPT

ChatGPT connects to remote MCP servers as a custom connector. Turn on developer
mode in ChatGPT's settings, add a connector, paste
`https://bilis.example.com/mcp` as its URL and choose OAuth as the
authentication. ChatGPT registers itself and sends you to the consent screen.
Claude on the web connects the same way, as a custom connector with that URL.

## Connect Gemini CLI

```bash
gemini mcp add --transport http bilis https://bilis.example.com/mcp
```

Gemini CLI discovers the OAuth server on first use and opens your browser to
sign in.

## Connect Claude Desktop, Cursor or anything else

Add one entry to the client's `mcpServers` block:

```json
{
    "mcpServers": {
        "bilis": {
            "url": "https://bilis.example.com/mcp"
        }
    }
}
```

GitHub Copilot in VS Code reads `.vscode/mcp.json`, which names the block
`servers` rather than `mcpServers`:

```json
{
    "servers": {
        "bilis": {
            "type": "http",
            "url": "https://bilis.example.com/mcp"
        }
    }
}
```

Restart the client and approve the sign-in when it opens your browser. Any MCP
client works the same way — it is a standard Streamable HTTP server with OAuth,
so the URL is the whole configuration. Cursor and VS Code hand back a
private-use URI scheme rather than a localhost URL; those are accepted.

## What your agent can do

Eleven tools, all of them reads, scoped to the teams you belong to.

| Tool              | What it does                                                                                                          |
| ----------------- | --------------------------------------------------------------------------------------------------------------------- |
| `list-teams`      | The teams you belong to, and which one the other tools default to.                                                    |
| `list-projects`   | A team's projects, and whether each has ever received logs, spans or metrics.                                         |
| `list-services`   | The service names a project actually sends, so the `service` filter is never a guess.                                 |
| `search-logs`     | Log lines over a window, filtered by service, severity, a full-text term, or a trace or span id.                      |
| `error-summary`   | A window's errors folded into distinct problems, with counts and a trace id to open. The "what broke?" call.          |
| `list-traces`     | Traces over a window, with duration and error count. Filter to failures or to anything above a threshold.             |
| `get-trace`       | One request as a text waterfall — every span, its service, duration, status and the attributes that locate a bug.     |
| `service-latency` | p95 and p99 per service over a window, slowest first.                                                                 |
| `list-metrics`    | The metrics a project reports, with type, unit and how each is read; given a name, its attribute keys and values.     |
| `query-metric`    | One metric as a time series — counters as a rate, histograms as p50/p95/p99 — with min/max/avg/last per series.       |
| `list-alerts`     | What is firing or pending now, and the recent alert history (fired, resolved, rule errors, new and regressed issues). |

Two prompts come with them: `instrument-with-bilis`, which teaches a fresh
assistant how to wire an app up to send here, and `debug-with-bilis`, which
turns a reported symptom into a plan across the tools above.

Every read is a time window, and it defaults to the last hour (`list-metrics`
and `list-alerts` look at the last day, a metric window stops at 30 days, and an
alert history window at 7). Pass `from` and `to` when the question is about
something older.

### `list-alerts`

Alert rules belong to a team, so this tool takes no `project`.

| Input   | Meaning                                                                                         |
| ------- | ----------------------------------------------------------------------------------------------- |
| `team`  | Team slug; omit for your current team.                                                          |
| `state` | Which active alerts to return: `all` (default — firing and pending), `firing` or `pending`.     |
| `from`  | Start of the history window, ISO-8601. Defaults to 24 hours before `to`; at most 7 days before. |
| `to`    | End of the history window, ISO-8601. Defaults to now.                                           |
| `limit` | History events to return, newest first, 1–200. Defaults to 50.                                  |

It returns `active` — every firing or pending alert (up to 200, firing first),
with its rule, source, severity, labels, last value, condition, threshold, since
when and whether the rule is muted — then `events`, the history in the window
(each with a type, time, title, summary and link; issue events also carry the
issue), and `truncated` when more events matched than `limit`. It reads alert
state Bilis already recorded; it never mutes, acknowledges or edits anything.

## What it deliberately cannot do

The MCP server is **read-only**. Your agent can read logs, traces, metrics and alerts and
list teams, projects and services. It cannot send a log line or a metric, delete anything, create
a project, read or issue an API key, change a setting, create or mute an alert rule, or start an Autofix job.
Those stay in the web app, behind a real click.

The access token expires after 24 hours and refreshes silently for up to 30
days while the client keeps using it. Approving a connection is always an
explicit click — Bilis has no trusted client that skips the consent screen.

## Self-hosting

The server is part of the app; there is nothing extra to run. It needs one thing
in the environment, and it needs it to stay the same across deploys:

```dotenv
PASSPORT_PRIVATE_KEY=
PASSPORT_PUBLIC_KEY=
```

Generate a pair with `php artisan passport:keys` and paste the PEM bodies into
those variables. Written to disk instead, they live in a container filesystem
that does not survive a deploy — and a regenerated pair invalidates every
connected agent silently, with nothing in the logs to say why.
