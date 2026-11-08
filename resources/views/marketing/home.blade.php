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
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-r from-background/90 via-background/50 to-transparent dark:to-background/20" aria-hidden="true"></div>

        {{-- Fade into the page ground so the band ends rather than stops. --}}
        <div class="pointer-events-none absolute inset-x-0 bottom-0 h-72 bg-gradient-to-b from-transparent via-background/80 to-background" aria-hidden="true"></div>

        <div class="relative mx-auto flex max-w-content flex-col gap-7 px-4 pt-16 pb-12 sm:px-6 sm:pt-28 sm:pb-14">
            <x-ui.eyebrow>
                <span>Self-hosted</span><span class="text-input">/</span>
                <span>Source-available</span><span class="text-input">/</span>
                <span>OpenTelemetry in</span>
            </x-ui.eyebrow>

            <h1 class="max-w-4xl text-5xl leading-none font-semibold tracking-display text-balance sm:text-6xl lg:text-display">
                Observability your coding agent can read.
            </h1>

            <div class="flex flex-col gap-8 border-t border-border pt-6 lg:flex-row lg:items-end lg:justify-between">
                <p class="max-w-2xl text-base leading-relaxed text-muted-foreground sm:text-lead">
                    Logs, traces and metrics on hardware you own, with
                    <span class="font-medium text-foreground">no per-gigabyte bill</span>. From
                    “something is wrong” to the exact line or span that explains it in seconds — and
                    your coding agent gets the same view: connect Claude Code, Cursor or any MCP
                    client in one line.
                </p>

                <div class="flex flex-wrap gap-3">
                    @auth
                        @if ($team = auth()->user()->currentTeam)
                            <x-ui.button size="lg" :href="route('dashboard', $team)">Open the app</x-ui.button>
                        @endif
                    @else
                        <x-ui.button size="lg" :href="route('register')">Create an account</x-ui.button>
                    @endauth
                    <x-ui.button size="lg" variant="outline" :href="route('features.mcp')">
                        Connect your agent
                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    </x-ui.button>
                </div>
            </div>
        </div>

        {{-- The product itself, in the app's own language. Not a screenshot. --}}
        <figure class="relative mx-auto max-w-content px-4 pb-16 sm:px-6 sm:pb-24">
            @include('marketing.partials.home.product-frame')
        </figure>
    </section>

    {{-- Four figures that answer "why this one", on hairlines like the
         dashboard's own strip. --}}
    <div class="mx-auto max-w-content px-4 sm:px-6">
        <dl class="grid grid-cols-2 border-y border-border lg:grid-cols-4" aria-label="Bilis at a glance">
            <x-ui.figure label="Signals" value="3 in 1" caption="logs, traces and metrics, linked" class="pr-6" />
            <x-ui.figure label="Per-gigabyte bill" value="None" caption="self-hosted retention costs only disk" class="border-l border-border pl-6" />
            <x-ui.figure label="To run it" value="1 app" caption="and one database, an afternoon to set up" class="border-t border-border pr-6 lg:border-t-0 lg:border-l lg:pl-6" />
            <x-ui.figure label="For your agent" value="10 tools" caption="read-only over MCP, one line to connect" class="border-t border-l border-border pl-6 lg:border-t-0" />
        </dl>
    </div>

    {{-- 01 — the investigation: the waterfall gets the large field, the
         stream answers from below. One trace id joins them in the app. --}}
    <section id="product" data-marketing-section class="mx-auto max-w-content scroll-mt-28 px-4 pt-20 sm:scroll-mt-16 sm:px-6 sm:pt-section">
        <x-ui.section-header number="01" label="Investigate">
            <x-slot:title>See the whole request. Then find the line that explains it.</x-slot:title>
            One trace id joins the waterfall and the stream. The investigation stays in one place,
            and each view is one click from the other.
        </x-ui.section-header>

        <div class="mt-12 grid gap-8 lg:grid-cols-12 lg:items-start">
            <div class="flex flex-col gap-3 lg:col-span-4 lg:pt-2 lg:pr-6">
                <x-ui.eyebrow>Traces</x-ui.eyebrow>
                <h3 class="text-xl font-semibold tracking-tight">Where the request spent its time</h3>
                <p class="text-sm leading-relaxed text-muted-foreground">
                    Every span in order, durations to scale, services separated, and the failure marked.
                    Select a span to read its attributes or jump straight to its logs.
                </p>
            </div>
            <div class="lg:col-span-8">
                @include('marketing.partials.home.waterfall')
            </div>
        </div>

        <div class="mt-12 grid gap-8 lg:grid-cols-12 lg:items-end">
            <figure class="order-2 lg:order-1 lg:col-span-8">
                @include('marketing.partials.live-tail')
                <figcaption class="mt-3 text-xs text-muted-foreground">Illustrative lines, rendered and tailed in the page itself — not a screenshot.</figcaption>
            </figure>
            <div class="order-1 flex flex-col gap-3 lg:order-2 lg:col-span-4 lg:pb-10 lg:pl-6">
                <x-ui.eyebrow>Logs</x-ui.eyebrow>
                <h3 class="text-xl font-semibold tracking-tight">What the code said about it</h3>
                <p class="text-sm leading-relaxed text-muted-foreground">
                    Search every line, narrow by time, project, service or severity, and leave a live
                    tail open while the deploy goes out. Every search is a URL you can share.
                </p>
            </div>
        </div>
    </section>

    {{-- 02 — connecting: the round trip on the left, the agent on the right. --}}
    <section id="ingest" data-marketing-section class="mx-auto max-w-content scroll-mt-28 px-4 pt-20 sm:scroll-mt-16 sm:px-6 sm:pt-section">
        <x-ui.section-header number="02" label="Connect">
            <x-slot:title>Works with the stack you already run.</x-slot:title>
            Point the OpenTelemetry exporter you already have at a new endpoint and add a key. Plain
            JSON covers everything else, and existing Sentry SDKs report straight in.
        </x-ui.section-header>

        <div class="mt-12 grid gap-6 lg:grid-cols-2">
            {{-- A round trip, not a snippet: the request and what actually
                 comes back. `LogIngestController` answers 202 with those two
                 counts, so the contract is shown rather than asserted. --}}
            <x-ui.panel>
                <x-slot:header>
                    <span class="eyebrow">Your services</span>
                    <button type="button"
                            hidden
                            data-copy="ingest-request"
                            class="-mr-1.5 rounded px-1.5 py-0.5 eyebrow transition-colors hover:bg-accent hover:text-accent-foreground">
                        <span data-copy-idle>Copy</span>
                        <span data-copy-done hidden class="text-severity-debug">Copied</span>
                    </button>
                </x-slot:header>

                <div class="scrollbar-stream overflow-x-auto px-5 py-5">
<pre id="ingest-request"
     class="font-mono text-xs leading-relaxed"
     data-copy-text='curl -X POST https://bilis.app/api/v1/ingest -H "Authorization: Bearer bilis_KEY" -H "Content-Type: application/json" -d "{\"severity\":\"error\",\"service\":\"billing\",\"message\":\"Stripe webhook timed out\"}"'><span class="text-muted-foreground"># The OTLP endpoint your exporter already knows.</span>
OTEL_EXPORTER_OTLP_LOGS_ENDPOINT=https://bilis.app/api/v1/logs

<span class="text-muted-foreground"># Or one log line, no collector, no SDK.</span>
curl -X POST https://bilis.app/api/v1/ingest \
  -H <span class="text-severity-debug">"Authorization: Bearer bilis_&hellip;"</span> \
  -H <span class="text-severity-debug">"Content-Type: application/json"</span> \
  -d '<span class="text-severity-debug">{"severity":"error","service":"billing","message":"Stripe webhook timed out"}</span>'</pre>
                </div>

                <div class="mt-auto flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-3">
                    <span class="eyebrow">Response <span class="font-mono tracking-normal text-severity-debug normal-case">202 Accepted</span></span>
                    <pre class="font-mono text-xs">{"accepted":<span class="text-severity-info">1</span>,"skipped":<span class="text-severity-info">0</span>}</pre>
                </div>
            </x-ui.panel>

            <x-ui.panel title="Your coding agent" meta="OAuth · read-only">
                <div class="flex flex-col gap-4 px-5 py-5 font-mono text-xs leading-5">
                    <p class="break-all"><span class="text-muted-foreground">$ </span>claude mcp add --transport http bilis {{ url('/mcp') }}</p>
                    <div class="flex flex-col gap-1 border-l-2 border-border pl-3">
                        <p class="text-muted-foreground">› Why did checkout fail in the last hour?</p>
                        <p><span class="text-chart-2" aria-hidden="true">●</span> bilis · error-summary</p>
                        <p><span class="text-chart-2" aria-hidden="true">●</span> bilis · get-trace <span class="text-muted-foreground">4bf92f35…</span></p>
                    </div>
                    <p class="font-sans text-sm leading-relaxed text-foreground/85">
                        Eight declines since 15:16, all from <code class="font-mono text-xs">payments</code> in
                        release <code class="font-mono text-xs">1.5.1</code>. The issuer call fails after 590 ms, and
                        <code class="font-mono text-xs">chargeCard()</code> does not handle insufficient funds.
                    </p>
                    <p class="font-sans text-xs text-muted-foreground">An illustrative session. The tools are the real ones — ten of them, all read-only.</p>
                </div>
            </x-ui.panel>
        </div>

        <x-marketing.agent-clients class="mt-6" />

        <p class="mt-5 text-xs leading-relaxed text-muted-foreground">
            A malformed record is skipped and counted, and the rest of the batch is still accepted.
            Writes are queued, so a 202 means accepted, not yet on disk.
            <a href="{{ route('docs.show', ['section' => 'ingestion', 'page' => 'claude-code']) }}"
               class="font-medium text-foreground underline underline-offset-4">Point Claude Code's own telemetry at Bilis &rarr;</a>
        </p>
    </section>

    {{-- 03 — the position: a manifesto on the left, a ruled ledger on the right. --}}
    <section id="why" data-marketing-section class="mx-auto max-w-content scroll-mt-28 px-4 pt-20 sm:scroll-mt-16 sm:px-6 sm:pt-section">
        <div class="grid gap-12 lg:grid-cols-12 lg:items-start">
            <div class="flex flex-col gap-5 lg:col-span-5">
                <x-ui.eyebrow number="03">Own it</x-ui.eyebrow>
                <h2 class="text-4xl leading-[1.08] font-semibold tracking-display text-balance sm:text-headline">
                    Keep the data. Lose the telemetry tax.
                </h2>
                <p class="max-w-md text-sm leading-relaxed text-muted-foreground">
                    Bilis is built for the team that owns the box, reads its own incidents, and does
                    not want observability to become another platform to operate.
                </p>
            </div>

            <dl class="border-t border-border lg:col-span-6 lg:col-start-7">
                @foreach ([
                    ['A', 'Your data never leaves the building', 'You own the box, so you own the logs — all of them, for as long as your disk allows. Retention costs the disk you already pay for, and a noisy deploy never shows up on an invoice.'],
                    ['B', 'Small enough to run yourself', 'The whole deployment is one app and one database. You can set it up in an afternoon and keep it healthy without a platform team.'],
                    ['C', 'Nothing to migrate away from', null],
                ] as [$letter, $title, $body])
                    <div class="flex gap-6 border-b border-border py-6">
                        <span class="w-6 shrink-0 font-mono text-xs leading-6 text-muted-foreground">{{ $letter }}</span>
                        <div class="flex flex-col gap-1.5">
                            <dt class="text-base font-semibold tracking-tight">{{ $title }}</dt>
                            <dd class="text-sm leading-relaxed text-muted-foreground">
                                @if ($body)
                                    {{ $body }}
                                @else
                                    Standard OpenTelemetry in, standard tables underneath. Your scripts, queries
                                    and AI tools work on the data today, and walking away is a
                                    <code class="font-mono text-xs text-foreground">SELECT</code>, not a migration.
                                    The code is <a href="{{ config('bilis.github_url') }}"
                                                   class="underline underline-offset-4 hover:text-foreground"
                                                   target="_blank" rel="noopener noreferrer">public</a>.
                                @endif
                            </dd>
                        </div>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>

    {{-- 04 — where it stands: shipped, next, and out on purpose, as a list
         in the app's own ruled style. --}}
    <section id="direction" data-marketing-section class="mx-auto max-w-content scroll-mt-28 px-4 pt-20 sm:scroll-mt-16 sm:px-6 sm:pt-section">
        <x-ui.section-header number="04" label="Status" :rule="false">
            <x-slot:title>Logs, traces and metrics today. Alerting next.</x-slot:title>
            Self-hosting stays first-class through all of it. eBPF collection, S3 tiering,
            replication and OTLP over gRPC stay a bigger platform's job.
        </x-ui.section-header>

        <div class="mt-10 border-t border-border">
            <div class="column-header hidden grid-cols-[12rem_minmax(0,1fr)_6rem] gap-6 border-b border-border py-2.5 sm:grid">
                <span>Capability</span><span>Detail</span><span class="text-right">Status</span>
            </div>
            <ul>
                @foreach ([
                    ['Ingest', 'OTLP logs, traces and metrics · plain JSON · Sentry-compatible SDKs', true],
                    ['Search', 'Fast full-text search across every log body', true],
                    ['Traces', 'Span waterfall, linked to logs in both directions', true],
                    ['Metrics', 'OTLP metrics charted as rates, levels and percentiles; hosts from one installer', true],
                    ['Issues', 'Exceptions grouped, with stack, breadcrumbs and regressions', true],
                    ['MCP', 'Coding agents search all of it, read-only, behind OAuth', true],
                    ['Autofix', 'An agent drafts the fix, with the failing trace attached', true],
                    ['Alerting', 'So the stack tells you when to look', false],
                ] as [$name, $detail, $shipped])
                    <li class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-6 gap-y-1 border-b border-border/70 py-3.5 sm:grid-cols-[12rem_minmax(0,1fr)_6rem]">
                        <span @class(['text-base font-medium tracking-tight', 'text-muted-foreground' => ! $shipped])>{{ $name }}</span>
                        <span class="order-3 col-span-2 text-sm text-muted-foreground sm:order-none sm:col-span-1">{{ $detail }}</span>
                        <span class="flex justify-end">
                            @if ($shipped)
                                <x-ui.badge>
                                    <svg class="size-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                                    Shipped
                                </x-ui.badge>
                            @else
                                <x-ui.badge variant="muted">Next</x-ui.badge>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- Closing: one raised surface, one decision. --}}
    <section id="start" data-marketing-section class="mx-auto max-w-content scroll-mt-28 px-4 py-20 sm:scroll-mt-16 sm:px-6 sm:py-section">
        <div class="flex flex-col gap-8 rounded-xl bg-card p-8 ring-1 ring-border sm:p-14 lg:flex-row lg:items-end lg:justify-between">
            <div class="flex max-w-xl flex-col gap-4">
                <x-ui.eyebrow>Start</x-ui.eyebrow>
                <h2 class="text-4xl leading-[1.08] font-semibold tracking-display text-balance sm:text-headline">
                    Bilis is early. The product is not hypothetical.
                </h2>
                <p class="text-sm leading-relaxed text-muted-foreground">
                    Run it on your own box today — free, with no plan — or sign up on bilis.app, where the
                    Free plan has published limits and no card. <a href="{{ route('pricing') }}"
                       class="underline underline-offset-2 transition-colors hover:text-foreground">See
                        pricing</a>. The source is on GitHub, so you can read exactly what it does with
                    your telemetry before you point anything at it.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                @auth
                    @if ($team = auth()->user()->currentTeam)
                        <x-ui.button size="lg" :href="route('dashboard', $team)">Open the app</x-ui.button>
                    @endif
                @else
                    <x-ui.button size="lg" :href="route('register')">Create an account</x-ui.button>
                @endauth
                <x-ui.button size="lg" variant="outline" :href="config('bilis.github_url')" external>
                    <x-icons.github class="size-4" />
                    Read the source
                </x-ui.button>
            </div>
        </div>
    </section>

    @push('scripts')
        {{-- The marketing bundle — shader, live tail, copy buttons. Still no Inertia. --}}
        @vite('resources/js/marketing/marketing.ts')
    @endpush
</x-layouts.marketing>
