@php
    /**
     * A still frame of the log viewer, drawn with the same tokens and column
     * widths the real stream uses. Illustrative lines — Bilis is pre-launch.
     */
    $stream = [
        ['14:02:11.418', 'error', 'text-severity-error', 'bg-severity-error', 'billing', 'Stripe webhook timed out after 30s (event evt_1P9xQ2)'],
        ['14:02:11.402', 'warn', 'text-severity-warn', 'bg-severity-warn', 'billing', 'Retry 3/5 for webhook delivery, backing off 8s'],
        ['14:02:09.771', 'info', 'text-severity-info', 'bg-severity-info', 'api', 'POST /v1/subscriptions 201 in 84ms'],
        ['14:02:09.688', 'debug', 'text-severity-debug', 'bg-severity-debug', 'api', 'Cache miss for plan:pro — reading from SQLite'],
        ['14:02:08.230', 'info', 'text-severity-info', 'bg-severity-info', 'worker', 'Processed 1 job in 12ms'],
        ['14:02:07.915', 'trace', 'text-severity-trace', 'bg-severity-trace', 'worker', 'Heartbeat ok, queue depth 0'],
    ];
@endphp

<x-layouts.marketing>
    {{-- Hero --}}
    <section class="mx-auto max-w-5xl px-6 pt-16 pb-12 sm:pt-24">
        <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
            Self-hosted log storage and search
        </p>

        <h1 class="mt-4 max-w-3xl text-3xl leading-tight font-semibold tracking-tight sm:text-5xl">
            Your logs, on your own box.
        </h1>

        <p class="mt-5 max-w-2xl text-sm leading-relaxed text-muted-foreground sm:text-base">
            Bilis takes logs over OTLP/HTTP, stores them in ClickHouse, and gives you a viewer
            built for finding one line among millions. One Laravel app and one database —
            no Grafana stack to operate, and no per-gigabyte bill at the end of the month.
        </p>

        <div class="mt-8 flex flex-wrap items-center gap-3">
            @auth
                @if ($team = auth()->user()->currentTeam)
                    <a href="{{ route('dashboard', $team) }}"
                       class="rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90">
                        Open the app
                    </a>
                @endif
            @else
                <a href="{{ route('register') }}"
                   class="rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90">
                    Create an account
                </a>
            @endauth

            <a href="#ingest"
               class="rounded-md border border-border px-5 py-2.5 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground">
                See how ingest works
            </a>
        </div>
    </section>

    {{-- The viewer --}}
    <section class="mx-auto max-w-5xl px-6 pb-16 sm:pb-24">
        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <div class="flex flex-wrap items-center gap-2 border-b border-border px-4 py-3">
                <span class="rounded-md border border-border px-2.5 py-1 text-xs font-medium">Last 15 minutes</span>
                <span class="rounded-md border border-border px-2.5 py-1 text-xs font-medium">billing</span>
                <span class="rounded-md bg-secondary px-2.5 py-1 text-xs font-medium text-secondary-foreground">
                    error + warn
                </span>
                <span class="ml-auto text-xs text-muted-foreground">Live tail</span>
                <span class="size-2 rounded-full bg-severity-info"></span>
            </div>

            <div class="overflow-x-auto">
                <div class="min-w-2xl">
                    @foreach ($stream as [$time, $severity, $textClass, $dotClass, $service, $message])
                        <div class="flex items-baseline gap-3 border-b border-border/60 px-3 py-1.5 last:border-b-0">
                            <span class="font-mono text-xs tabular-nums text-muted-foreground">{{ $time }}</span>
                            <span class="flex w-16 shrink-0 items-center gap-1.5">
                                <span class="size-2 shrink-0 rounded-full {{ $dotClass }}"></span>
                                <span class="text-xs font-medium {{ $textClass }}">{{ $severity }}</span>
                            </span>
                            <span class="w-40 shrink-0 truncate font-mono text-xs text-muted-foreground">{{ $service }}</span>
                            <span class="flex-1 truncate font-mono text-xs">{{ $message }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <p class="mt-3 text-xs text-muted-foreground">
            Time range, project, service and severity filters, full-text search, and a live tail you can leave open during a deploy.
        </p>
    </section>

    {{-- Ingest --}}
    <section id="ingest" class="border-y border-border bg-card/40">
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            <h2 class="text-xl font-semibold tracking-tight">Point anything that speaks OTLP at it</h2>
            <p class="mt-3 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                Standard OTLP/HTTP with standard OTel columns, so any exporter you already run works
                and the data can be taken elsewhere. If you would rather not run a collector, there is
                a plain JSON endpoint that takes one record or a list of them.
            </p>

            <div class="mt-8 overflow-x-auto rounded-xl border border-border bg-card p-4">
                <pre class="font-mono text-xs leading-relaxed"><span class="text-muted-foreground"># One log line, no collector, no SDK.</span>
curl -X POST https://logs.example.com/api/v1/ingest \
  -H <span class="text-severity-debug">"Authorization: Bearer bilis_&hellip;"</span> \
  -H <span class="text-severity-debug">"Content-Type: application/json"</span> \
  -d '<span class="text-severity-debug">{"severity":"error","service":"billing","message":"Stripe webhook timed out"}</span>'

<span class="text-muted-foreground"># Or the OTLP endpoint your exporter already knows.</span>
OTEL_EXPORTER_OTLP_LOGS_ENDPOINT=https://logs.example.com/api/v1/logs</pre>
            </div>

            <p class="mt-4 max-w-2xl text-xs leading-relaxed text-muted-foreground">
                Ingest never blames the client: malformed records are skipped with a count rather than
                failing the batch. Writes are queued asynchronously, so a success means accepted, not yet durable.
            </p>
        </div>
    </section>

    {{-- The three claims --}}
    <section class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
        <div class="grid gap-10 sm:grid-cols-3">
            <div>
                <h3 class="text-base font-semibold tracking-tight">Self-hosted, no per-GB pricing</h3>
                <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                    You own the box and you own the data. What retention costs is disk, which you already
                    pay for — not a metered bill that punishes a noisy deploy.
                </p>
            </div>
            <div>
                <h3 class="text-base font-semibold tracking-tight">Simple to run</h3>
                <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                    One Laravel app and one ClickHouse. That is the entire deployment. Operational
                    simplicity is the product here, not a side effect of it.
                </p>
            </div>
            <div>
                <h3 class="text-base font-semibold tracking-tight">OTel-native, no lock-in</h3>
                <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                    Standard OTLP/HTTP ingest into an OTel-compatible schema. Nothing about your data
                    is shaped so that leaving is hard.
                </p>
            </div>
        </div>
    </section>

    {{-- Scope --}}
    <section class="border-t border-border">
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            <h2 class="text-xl font-semibold tracking-tight">What Bilis does, and what it deliberately does not</h2>
            <p class="mt-3 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                Being narrow is the point. Everything below the line is a thing you should use another
                tool for — and we would rather say so than imply it is coming.
            </p>

            <div class="mt-8 grid gap-8 sm:grid-cols-2">
                <div class="rounded-xl border border-border bg-card p-6">
                    <h3 class="text-sm font-semibold tracking-tight">In the product</h3>
                    <ul class="mt-3 space-y-2 text-sm text-muted-foreground">
                        <li>OTLP/HTTP JSON ingest, plus a simple JSON endpoint</li>
                        <li>OTel-compatible ClickHouse logs table</li>
                        <li>Time range, project, service and severity filters</li>
                        <li>Full-text search across log bodies</li>
                        <li>Live tail</li>
                        <li>Teams, projects, and scoped API keys</li>
                    </ul>
                </div>
                <div class="rounded-xl border border-border bg-card p-6">
                    <h3 class="text-sm font-semibold tracking-tight">Not in the product</h3>
                    <ul class="mt-3 space-y-2 text-sm text-muted-foreground">
                        <li>Traces and metrics</li>
                        <li>Alerting and on-call</li>
                        <li>Dashboards and saved searches</li>
                        <li>eBPF collection</li>
                        <li>S3 tiering and replication</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- Closing --}}
    <section class="border-t border-border bg-card/40">
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            <h2 class="text-xl font-semibold tracking-tight">Bilis is early</h2>
            <p class="mt-3 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                There is no hosted tier yet and nothing to buy. What exists is the product itself —
                run it on your own infrastructure and read your logs. If a hosted option lands later,
                self-hosting stays the first-class way to use it.
            </p>

            <div class="mt-8">
                @auth
                    @if ($team = auth()->user()->currentTeam)
                        <a href="{{ route('dashboard', $team) }}"
                           class="inline-block rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90">
                            Open the app
                        </a>
                    @endif
                @else
                    <a href="{{ route('register') }}"
                       class="inline-block rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90">
                        Create an account
                    </a>
                @endauth
            </div>
        </div>
    </section>
</x-layouts.marketing>
