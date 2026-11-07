---
title: Give your coding agent your production logs
description: How to let Claude Code, Cursor or any MCP client read your logs, traces and metrics while it works — read-only, over OAuth, on your own box.
date: 2026-10-10
author: Samuel Vrablik
draft: true
---

A coding agent fixing a production bug usually works from a description of the
symptom. Someone pastes a stack trace into the chat, or types "checkout returns
500 since the deploy", and the agent reads the code and guesses which path
produced it. It is often right. When it is wrong, it is wrong confidently,
because the one thing that would settle the question — what actually happened
at 14:02 on the request that failed — is in a log viewer the agent cannot see.

The Model Context Protocol fixes the "cannot see" part. An MCP server is a small
set of tools an agent can call, and an observability backend that exposes one
lets the agent read the same logs and traces you would. This post is about
doing that well: what to connect, what the agent should be allowed to do, and
what a debugging session looks like once it can look for itself.

## The one-line setup

If your logs, traces and metrics are in Bilis, the MCP server is already
running — it is part of the app, at `/mcp` on your instance. In Claude Code:

```bash
claude mcp add --transport http bilis https://bilis.example.com/mcp
```

Cursor, Claude Desktop and every other MCP client take the same URL in their
`mcpServers` block. There is no API key to paste. The first time the client
connects, a browser opens, you sign in as yourself, and you approve the
connection on a consent screen. The client registers itself; the token is
scoped to the teams you belong to and expires on its own.

The [MCP reference](/docs/reference/mcp) has the exact configuration for each
client and the self-hosting notes.

## What the agent gets

Ten tools, every one of them a read:

- **`error-summary`** folds a window's errors into distinct problems with counts
  and a trace id each. This is the "what broke?" call, and it matters more than
  it sounds: an agent handed four thousand identical lines spends its context on
  repetition, while an agent handed six groups can reason about which one lines
  up with the symptom.
- **`search-logs`** reads lines over a window, filtered by service, severity, a
  term, or a trace id — so the agent can read what happened *before* the
  failure, which is usually where the cause is.
- **`get-trace`** returns one request as a text waterfall: every span, its
  service, duration and status, and the attributes that locate a bug. A cause
  two services upstream of the log line is common, and the waterfall is where it
  shows.
- **`service-latency`**, **`list-traces`**, **`list-metrics`** and
  **`query-metric`** cover the other kind of incident — slow rather than broken,
  or a pool, a queue or memory running out.
- **`list-teams`**, **`list-projects`** and **`list-services`** so the filters are
  never a guess.

Two prompts ship with them. `debug-with-bilis` takes a symptom and turns it into
a plan across the tools; `instrument-with-bilis` teaches a fresh agent how to
wire an application up to send telemetry in the first place.

## What a session looks like

Say the report is "checkout 500s since the deploy". With the server connected,
the agent does not start in the code. It starts in the data:

1. `error-summary` for the window since the deploy. One group stands out, not
   because it is the loudest but because its first occurrence lines up with the
   deploy time.
2. `search-logs` on that group's service, around the first occurrence. The lines
   just before the error say more than the error does.
3. `get-trace` on the trace id from one of those lines. The waterfall shows the
   request failing in a downstream service, not in the checkout handler where the
   exception was finally logged.

Only then does it open the code — the downstream service's code, at the span
that failed — and propose a change. It can quote the log line and the span it
based the fix on, and it can say which part of its explanation is evidence and
which is inference. That last part is in the `debug-with-bilis` prompt on
purpose: an agent that has read the symptoms has not read the code path, and it
should say so.

## Why read-only

Every tool on the server is a read. The agent cannot send a log line, delete
data, create a project, see an API key, change a setting or start an automated
fix. Those stay in the web app behind a click.

That is a deliberate line rather than a missing feature. An agent with write
access to your telemetry can, by accident, destroy the evidence it was asked to
examine — and an agent that can mint API keys is a credential leak waiting for a
prompt injection. Reading is what debugging needs. Anything that changes state
is a decision a person should make.

## Why where the data lives matters

Several hosted observability vendors now offer an MCP server of their own, and
if your telemetry is already there, connecting it is a fine first step. The
difference with a self-hosted backend is where the reads go. When the agent
searches your logs, it is querying a database on your own server; the logs do
not pass through another company on their way to the model. For teams whose
logs carry customer data, that is often the difference between "we can try this"
and "legal needs to look at it first".

It also changes the cost of being thorough. On a per-GB bill, the instinct is to
log less and sample harder; an agent that can read everything is most useful
when everything was kept. If you want to see what that trade looks like in
numbers, the [log volume and cost calculator](/tools/log-cost-calculator) works
it out for your own rate.

## Getting started

- Already sending OpenTelemetry? Point the exporter at Bilis — the
  [endpoints](/docs/ingestion/endpoints) page has the contract — and add the MCP
  server with the line above.
- Starting from nothing? The [quickstart](/docs/getting-started/quickstart) goes
  from a new account to a log line in three steps, and the
  [MCP page](/features/mcp) shows the rest.
