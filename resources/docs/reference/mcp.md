---
title: MCP server
description: Connect Claude Code, Claude Desktop, Cursor or any MCP client to Bilis in one line, and let your agent read your logs and traces while it works.
order: 2
---

Bilis speaks the [Model Context Protocol](https://modelcontextprotocol.io). Point
an AI client at your instance and it can search your logs, open a trace as a
waterfall, and tell you which service is slow — while it is working in the
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

Restart the client and approve the sign-in when it opens your browser. Any MCP
client works the same way — it is a standard Streamable HTTP server with OAuth,
so the URL is the whole configuration. Cursor and VS Code hand back a
private-use URI scheme rather than a localhost URL; those are accepted.

## What your agent can do

Eight tools, all of them reads, scoped to the teams you belong to.

| Tool               | What it does                                                                                                 |
| ------------------ | ------------------------------------------------------------------------------------------------------------ |
| `list-teams`       | The teams you belong to, and which one the other tools default to.                                           |
| `list-projects`    | A team's projects, and whether each has ever received logs or spans.                                         |
| `list-services`    | The service names a project actually sends, so the `service` filter is never a guess.                        |
| `search-logs`      | Log lines over a window, filtered by service, severity, a full-text term, or a trace or span id.              |
| `error-summary`    | A window's errors folded into distinct problems, with counts and a trace id to open. The "what broke?" call.  |
| `list-traces`      | Traces over a window, with duration and error count. Filter to failures or to anything above a threshold.     |
| `get-trace`        | One request as a text waterfall — every span, its service, duration, status and the attributes that locate a bug. |
| `service-latency`  | p95 and p99 per service over a window, slowest first.                                                        |

Two prompts come with them: `instrument-with-bilis`, which teaches a fresh
assistant how to wire an app up to send here, and `debug-with-bilis`, which
turns a reported symptom into a plan across the tools above.

Every read is a time window, and it defaults to the last hour. Pass `from` and
`to` when the question is about something older.

## What it deliberately cannot do

The MCP server is **read-only**. Your agent can read logs and traces and list
teams, projects and services. It cannot send a log line, delete anything, create
a project, read or issue an API key, change a setting, or start an Autofix job.
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
