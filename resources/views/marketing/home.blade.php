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
            <picture>
                <source media="(prefers-color-scheme: dark)" srcset="/screenshot-logs-dark.png">
                <img src="/screenshot-logs-light.png" alt="The Bilis log viewer: a live tail with time-range, project, service and severity filters above a scrolling stream of timestamped, severity-coloured log lines." width="1440" height="900" loading="lazy">
            </picture>
        </div>

        <p class="mt-3 text-xs text-muted-foreground">
            Time range, project, service and severity filters, full-text search, and a live tail you can leave open during a deploy. Log lines shown are illustrative — Bilis is pre-launch.
        </p>
    </section>

    {{-- Ingest --}}
    <section id="ingest" class="border-y border-border bg-card">
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            <h2 class="text-xl font-semibold tracking-tight">Point anything that speaks OTLP at it</h2>
            <p class="mt-3 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                Standard OTLP/HTTP with standard OTel columns, so any exporter you already run works
                and the data can be taken elsewhere. If you would rather not run a collector, there is
                a plain JSON endpoint that takes one record or a list of them.
            </p>

            <div class="mt-8 overflow-x-auto rounded-xl border border-border bg-background p-4">
                <pre class="font-mono text-xs leading-relaxed"><span class="text-muted-foreground"># One log line, no collector, no SDK.</span>
curl -X POST https://bilis.app/api/v1/ingest \
  -H <span class="text-severity-debug">"Authorization: Bearer bilis_&hellip;"</span> \
  -H <span class="text-severity-debug">"Content-Type: application/json"</span> \
  -d '<span class="text-severity-debug">{"severity":"error","service":"billing","message":"Stripe webhook timed out"}</span>'

<span class="text-muted-foreground"># Or the OTLP endpoint your exporter already knows.</span>
OTEL_EXPORTER_OTLP_LOGS_ENDPOINT=https://bilis.app/api/v1/logs</pre>
            </div>

            <p class="mt-4 max-w-2xl text-xs leading-relaxed text-muted-foreground">
                Ingest never blames the client: malformed records are skipped with a count rather than
                failing the batch. Writes are queued asynchronously, so a success means accepted, not yet durable.
            </p>

            <p class="mt-8 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                The key comes from a project in the app — one key, one project, revocable any time:
            </p>

            <div class="mt-4 max-w-2xl overflow-hidden rounded-xl border border-border bg-background">
                <picture>
                    <source media="(prefers-color-scheme: dark)" srcset="/screenshot-project-dark.png">
                    <img src="/screenshot-project-light.png" alt="A Bilis project's API keys panel, showing three issued keys with their creation and last-used dates." width="1440" height="900" loading="lazy">
                </picture>
            </div>
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
                    is shaped so that leaving is hard, and the code that handles it is
                    <a href="{{ config('bilis.github_url') }}" class="underline underline-offset-2 hover:text-foreground"
                       target="_blank" rel="noopener noreferrer">public</a>.
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
    <section class="border-t border-border bg-card">
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            <h2 class="text-xl font-semibold tracking-tight">Bilis is early</h2>
            <p class="mt-3 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                There is no hosted tier yet and nothing to buy. What exists is the product itself —
                run it on your own infrastructure and read your logs. If a hosted option lands later,
                self-hosting stays the first-class way to use it.
            </p>

            <p class="mt-4 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                The whole thing is open on GitHub, so you can read exactly what it does with your
                logs before you point anything at it.
            </p>

            <div class="mt-8 flex flex-wrap items-center gap-3">
                @auth
                    @if ($team = auth()->user()->currentTeam)
                        <a href="{{ route('dashboard', $team) }}"
                           class="inline-block rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90">
                            Open the app
                        </a>
                    @endif
                @else
                    <a href="{{ route('register') }}"
                       class="rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90">
                        Create an account
                    </a>
                @endauth

                <a href="{{ config('bilis.github_url') }}"
                   class="flex items-center gap-2 rounded-md border border-border px-5 py-2.5 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground"
                   target="_blank" rel="noopener noreferrer">
                    <x-icons.github class="size-4" />
                    Read the source
                </a>
            </div>
        </div>
    </section>
</x-layouts.marketing>
