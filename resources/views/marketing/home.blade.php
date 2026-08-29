<x-layouts.marketing>
    {{-- Hero and the live tail share one band.

         The band is the shader surface: Paper's ShaderMount prepends a
         `z-index: -1` canvas and isolates the element, so the headline and
         the stream both sit on the same drifting sheets.

         Two palettes, one per ground, because light is authored rather than
         derived: `data-light-*` overrides the dark stops when the reader's
         OS is light. Both are built out of the mark's tail — navy, teal and
         gold — around the page's own `--background`, so the gaps between the
         sheets *are* the page and the band dissolves into it either way. --}}
    <section
        data-fold-gradient
        data-colors="#0d1420,#1f3a5f,#45bfa6,#f3c440,#f3f0e7"
        data-bg-color="#111317"
        data-shadow-color="#141d29"
        data-softness="0.9"
        data-saturation="1.05"
        data-rotation="-25"
        data-zoom="10"
        data-speed="1"
        data-light-colors="#e4eaf3,#a9c6e2,#66c3ad,#dcb85e,#4a6c94"
        data-light-bg-color="#f6f7f9"
        data-light-shadow-color="#dfe5ee"
        data-light-saturation="0.9"
        data-light-softness="1.4"
        class="relative isolate overflow-hidden bg-background bg-[radial-gradient(120%_120%_at_15%_0%,#eaeef4_0%,#f2f4f7_45%,#f6f7f9_100%)] dark:bg-[radial-gradient(120%_120%_at_15%_0%,#1f3a5f_0%,#141d29_45%,#111317_100%)]"
    >
        {{-- Scrim: the sheets are bright where they are bright, and the
             headline has to win regardless of where they drift. --}}
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-r from-background/90 via-background/45 to-transparent dark:to-background/10" aria-hidden="true"></div>

        {{-- Fade into the page ground so the band ends rather than stops. --}}
        <div class="pointer-events-none absolute inset-x-0 bottom-0 h-48 bg-gradient-to-b from-transparent via-background/70 to-background" aria-hidden="true"></div>

        <div class="relative mx-auto max-w-5xl px-6 pt-16 pb-12 sm:pt-24">
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
                   class="rounded-md border border-border px-5 py-2.5 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground dark:border-foreground/25 dark:hover:bg-foreground/10 dark:hover:text-foreground">
                    See how ingest works
                </a>
            </div>
        </div>

        {{-- The stream itself, tailing. Not a picture of one. --}}
        <div class="relative mx-auto max-w-5xl px-6 pb-16 sm:pb-24">
            @include('marketing.partials.live-tail')

            <p class="mt-3 text-xs text-muted-foreground">
                A live tail, running here on the page. Log lines shown are illustrative — Bilis is pre-launch.
            </p>
        </div>
    </section>

    {{-- The viewer --}}
    <section class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
        @include('marketing.partials.section-label', ['number' => '01', 'label' => 'The viewer'])

        <h2 class="mt-4 text-xl font-semibold tracking-tight">Built for finding one line among millions</h2>
        <p class="mt-3 max-w-2xl text-sm leading-relaxed text-muted-foreground">
            Time range, project, service and severity filters, full-text search, and a live tail
            you can leave open during a deploy.
        </p>

        <div class="mt-8 overflow-hidden rounded-xl border border-border bg-card">
            <picture>
                <source media="(prefers-color-scheme: dark)"
                        srcset="/screenshot-logs-dark.png">
                <img src="/screenshot-logs-light.png"
                     alt="The Bilis log viewer: a live tail with time-range, project, service and severity filters above a scrolling stream of timestamped, severity-coloured log lines."
                     width="1440"
                     height="900"
                     loading="lazy">
            </picture>
        </div>
    </section>

    {{-- Ingest --}}
    <section id="ingest" class="border-y border-border bg-card">
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            @include('marketing.partials.section-label', ['number' => '02', 'label' => 'Ingest'])

            <h2 class="mt-4 text-xl font-semibold tracking-tight">Point anything that speaks OTLP at it</h2>
            <p class="mt-3 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                Standard OTLP/HTTP with standard OTel columns, so any exporter you already run works
                and the data can be taken elsewhere. If you would rather not run a collector, there is
                a plain JSON endpoint that takes one record or a list of them.
            </p>

            {{-- A round trip, not a snippet: the request and what actually
                 comes back. `LogIngestController` answers 202 with those two
                 counts, so the "ingest never blames the client" invariant is
                 shown rather than asserted. --}}
            <div class="mt-8 overflow-hidden rounded-xl border border-border bg-background">
                <div class="flex items-center justify-between gap-4 border-b border-border px-4 py-2">
                    <span class="font-mono text-[11px] tracking-[0.14em] text-muted-foreground uppercase">Request</span>

                    <button type="button"
                            hidden
                            data-copy="ingest-request"
                            class="-mr-1.5 rounded px-1.5 py-0.5 font-mono text-[11px] tracking-[0.14em] text-muted-foreground uppercase transition-colors hover:bg-accent hover:text-accent-foreground">
                        <span data-copy-idle>Copy</span>
                        <span data-copy-done
                              hidden
                              class="text-severity-debug">Copied</span>
                    </button>
                </div>

                <div class="overflow-x-auto px-4 py-4">
<pre id="ingest-request"
     class="font-mono text-xs leading-relaxed"
     data-copy-text='curl -X POST https://bilis.app/api/v1/ingest -H "Authorization: Bearer bilis_KEY" -H "Content-Type: application/json" -d "{\"severity\":\"error\",\"service\":\"billing\",\"message\":\"Stripe webhook timed out\"}"'><span class="text-muted-foreground"># One log line, no collector, no SDK.</span>
curl -X POST https://bilis.app/api/v1/ingest \
  -H <span class="text-severity-debug">"Authorization: Bearer bilis_&hellip;"</span> \
  -H <span class="text-severity-debug">"Content-Type: application/json"</span> \
  -d '<span class="text-severity-debug">{"severity":"error","service":"billing","message":"Stripe webhook timed out"}</span>'

<span class="text-muted-foreground"># Or the OTLP endpoint your exporter already knows.</span>
OTEL_EXPORTER_OTLP_LOGS_ENDPOINT=https://bilis.app/api/v1/logs</pre>
                </div>

                <div class="border-t border-border px-4 py-3">
                    <span class="font-mono text-[11px] tracking-[0.14em] text-muted-foreground uppercase">
                        Response <span class="text-severity-debug">202 Accepted</span>
                    </span>
                    <pre class="mt-2 overflow-x-auto font-mono text-xs leading-relaxed">{"accepted":<span class="text-severity-info">1</span>,"skipped":<span class="text-severity-info">0</span>}</pre>
                </div>
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
                    {{-- Cropped to the keys themselves: the bottom half of
                         the capture is empty page, and empty page is not the
                         thing being shown. --}}
                    <img src="/screenshot-project-light.png"
                         alt="A Bilis project's API keys panel, showing three issued keys with their creation and last-used dates."
                         width="1440"
                         height="900"
                         loading="lazy"
                         class="h-[280px] w-full object-cover object-top">
                </picture>
            </div>
        </div>
    </section>

    {{-- The three claims --}}
    <section class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
        @include('marketing.partials.section-label', ['number' => '03', 'label' => 'Why'])

        <div class="mt-8 grid gap-10 sm:grid-cols-3">
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
            @include('marketing.partials.section-label', ['number' => '04', 'label' => 'Scope'])

            <h2 class="mt-4 text-xl font-semibold tracking-tight">What Bilis does, and what it deliberately does
                not</h2>
            <p class="mt-3 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                Being narrow is the point. Everything below the line is a thing you should use another
                tool for — and we would rather say so than imply it is coming.
            </p>

            {{-- A ledger, not two cards: two columns read across as one table,
                 on the same hairlines, so the omissions carry the same weight
                 as the features. --}}
            <div class="mt-8 grid gap-x-12 gap-y-10 sm:grid-cols-2">
                <div>
                    <p class="font-mono text-[11px] tracking-[0.18em] text-muted-foreground uppercase">In the
                        product</p>

                    <ul class="mt-3 divide-y divide-border border-t border-border">
                        @foreach ([
                            'OTLP/HTTP JSON ingest, plus a simple JSON endpoint',
                            'OTel-compatible ClickHouse logs table',
                            'Time range, project, service and severity filters',
                            'Full-text search across log bodies',
                            'Live tail',
                            'Teams, projects, and scoped API keys',
                        ] as $item)
                            <li class="flex items-baseline gap-3 py-2.5 text-sm">
                                <span class="font-mono text-severity-debug"
                                      aria-hidden="true">+</span>
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div>
                    <p class="font-mono text-[11px] tracking-[0.18em] text-muted-foreground uppercase">Not in the
                        product</p>

                    <ul class="mt-3 divide-y divide-border border-t border-border">
                        @foreach ([
                            'Traces and metrics',
                            'Alerting and on-call',
                            'Dashboards and saved searches',
                            'eBPF collection',
                            'S3 tiering and replication',
                        ] as $item)
                            <li class="flex items-baseline gap-3 py-2.5 text-sm text-muted-foreground">
                                <span class="font-mono"
                                      aria-hidden="true">&minus;</span>
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- Closing --}}
    <section class="border-t border-border bg-card">
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            @include('marketing.partials.section-label', ['number' => '05', 'label' => 'Where it stands'])

            <h2 class="mt-4 text-xl font-semibold tracking-tight">Bilis is early</h2>
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

    @push('scripts')
        {{-- The marketing bundle — shader, live tail, copy buttons. Still no Inertia. --}}
        @vite('resources/js/marketing/marketing.ts')
    @endpush
</x-layouts.marketing>
