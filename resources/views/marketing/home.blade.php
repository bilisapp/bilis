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

        <div class="relative mx-auto max-w-5xl px-6 pt-20 pb-12 sm:pt-28 sm:pb-16">
            <h1 class="max-w-4xl text-4xl leading-[0.96] font-semibold tracking-[-0.04em] text-balance sm:text-6xl lg:text-7xl">
                <span class="block">Your logs and traces.</span>
                <span class="block">On your own box.</span>
            </h1>

            <div class="mt-10 grid gap-8 border-t border-foreground/15 pt-6 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start dark:border-foreground/20">
                <p class="max-w-2xl text-sm leading-relaxed text-muted-foreground sm:text-base">
                    When something breaks, the answer is already in your telemetry. Bilis gets you from
                    “something is wrong” to the exact log line — or the exact span — that explains it,
                    in seconds, on hardware you own, with no per-gigabyte bill for the privilege. And it
                    is only the start: Bilis is growing into a self-hosted observability stack where AI
                    reads alongside you, spotting what matters and helping you fix it.
                </p>

                <div class="flex flex-wrap gap-3 sm:flex-nowrap sm:justify-end">
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
                        See ingest
                    </a>
                </div>
            </div>
        </div>

        {{-- The stream itself, tailing. Not a picture of one. --}}
        <figure class="relative mx-auto max-w-5xl px-6 pb-16 sm:pb-24">
            <figcaption class="mb-3 flex items-center justify-between gap-4 border-t border-foreground/15 pt-3 font-mono text-[11px] tracking-[0.14em] text-muted-foreground uppercase dark:border-foreground/20">
                <span>Live proof, not a mockup</span>
                <span class="hidden text-right sm:block">Illustrative data · running in the page</span>
            </figcaption>

            @include('marketing.partials.live-tail')
        </figure>
    </section>

    {{-- Product evidence sets the page's rhythm: traces get the large field,
         logs answer from a smaller, offset field below. --}}
    <section id="product" data-marketing-section class="mx-auto max-w-5xl scroll-mt-28 px-6 py-20 sm:scroll-mt-16 sm:py-28">
        <div class="grid gap-8 border-b border-border pb-10 sm:grid-cols-12 sm:items-end">
            <h2 class="text-3xl leading-tight font-semibold tracking-[-0.035em] text-balance sm:col-span-7 sm:text-4xl">
                See the whole request.<br>
                Then find the line that explains it.
            </h2>
            <p class="text-sm leading-relaxed text-muted-foreground sm:col-span-4 sm:col-start-9">
                One trace id joins the waterfall and the stream. The investigation stays in one place,
                and each view is one click from the other.
            </p>
        </div>

        <div class="mt-12 grid gap-6 lg:grid-cols-12 lg:items-start">
            <div class="lg:col-span-3 lg:pt-8">
                <p class="font-mono text-[11px] tracking-[0.18em] text-muted-foreground uppercase">Traces</p>
                <h3 class="mt-3 text-lg font-semibold tracking-tight">Where the request spent its time</h3>
                <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                    Every span in order, durations to scale, services separated, and the failure marked.
                    Select a span to inspect its attributes or jump straight to its logs.
                </p>
            </div>

            <figure class="overflow-hidden rounded-lg border border-border bg-card transition-transform duration-300 ease-out hover:-translate-y-1 motion-reduce:transform-none motion-reduce:transition-none lg:col-span-9">
                <picture>
                    <source media="(prefers-color-scheme: dark)"
                            srcset="/screenshot-trace-dark.png">
                    <img src="/screenshot-trace-light.png"
                         alt="A trace waterfall in Bilis: a deploy request across three services — keel-api, keel-db and keel-worker — with each span's duration drawn to scale and a detail panel listing the selected span's attributes."
                         width="1440"
                         height="900"
                         loading="lazy">
                </picture>
            </figure>
        </div>

        <div class="mt-16 grid gap-6 lg:grid-cols-12 lg:items-end">
            <figure class="order-2 overflow-hidden rounded-lg border border-border bg-card transition-transform duration-300 ease-out hover:-translate-y-1 motion-reduce:transform-none motion-reduce:transition-none lg:order-1 lg:col-span-8">
                <picture>
                    <source media="(prefers-color-scheme: dark)"
                            srcset="/screenshot-logs-dark.png">
                    <img src="/screenshot-logs-light.png"
                         alt="The Bilis log viewer: severity-coloured log lines under a volume histogram, with full-text search, scope, time-window and severity filters above the stream."
                         width="1440"
                         height="900"
                         loading="lazy">
                </picture>
            </figure>

            <div class="order-1 lg:order-2 lg:col-span-4 lg:pb-8 lg:pl-4">
                <p class="font-mono text-[11px] tracking-[0.18em] text-muted-foreground uppercase">Logs</p>
                <h3 class="mt-3 text-lg font-semibold tracking-tight">What the code said about it</h3>
                <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                    Search every line, narrow by time, project, service or severity, and leave a live
                    tail open while the deploy goes out. Every search is a URL you can share.
                </p>
            </div>
        </div>
    </section>

    {{-- Ingest reads as a simple input/output instrument, not another
         stack of explanatory cards. --}}
    <section id="ingest" data-marketing-section class="scroll-mt-28 border-y border-border bg-card sm:scroll-mt-16">
        <div class="mx-auto max-w-5xl px-6 py-20 sm:py-28">
            <div class="grid gap-10 lg:grid-cols-12 lg:items-start">
                <div class="lg:col-span-4">
                    <h2 class="text-3xl leading-tight font-semibold tracking-[-0.035em] text-balance sm:text-4xl">
                        Works with the stack you already run.
                    </h2>
                    <p class="mt-5 text-sm leading-relaxed text-muted-foreground">
                        If your tooling speaks OpenTelemetry — and most already does — sending logs and
                        traces to Bilis is a configuration change, not a project. No agents to deploy, no
                        SDK to adopt, nothing to rewrite.
                    </p>
                    <p class="mt-4 text-sm leading-relaxed text-muted-foreground">
                        Plain JSON covers everything else. Existing Sentry SDKs can report straight to
                        Bilis too.
                    </p>
                </div>

                {{-- A round trip, not a snippet: the request and what actually
                     comes back. `LogIngestController` answers 202 with those two
                     counts, so the contract is shown rather than asserted. --}}
                <div class="overflow-hidden rounded-lg border border-border bg-background lg:col-span-7 lg:col-start-6">
                    <div class="flex items-center justify-between gap-4 border-b border-border px-4 py-2">
                        <span class="font-mono text-[11px] tracking-[0.14em] text-muted-foreground uppercase">Request</span>

                        <button type="button"
                                hidden
                                data-copy="ingest-request"
                                class="-mr-1.5 rounded px-1.5 py-0.5 font-mono text-[11px] tracking-[0.14em] text-muted-foreground uppercase transition-colors hover:bg-accent hover:text-accent-foreground">
                            <span data-copy-idle>Copy</span>
                            <span data-copy-done hidden class="text-severity-debug">Copied</span>
                        </button>
                    </div>

                    <div class="scrollbar-stream overflow-x-auto px-4 py-5">
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
            </div>

            <p class="mt-5 border-t border-border pt-4 text-xs leading-relaxed text-muted-foreground lg:ml-[41.666667%]">
                Ingest never blames the client: malformed records are skipped with a count rather than
                failing the batch. Writes are queued asynchronously, so a success means accepted, not yet durable.
            </p>

            <div class="mt-16 grid gap-10 border-t border-border pt-12 lg:grid-cols-12">
                <div class="lg:col-span-4">
                    <h3 class="text-lg font-semibold tracking-tight">Your coding agent counts too</h3>
                    <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                        Claude Code speaks OpenTelemetry out of the box. A few lines of configuration put
                        every prompt, tool call, token and dollar it spends beside the application it is
                        editing — no plugin, nothing in between.
                    </p>
                    <a href="{{ route('docs.show', ['section' => 'ingestion', 'page' => 'claude-code']) }}"
                       class="mt-4 inline-block text-sm font-medium underline underline-offset-4 hover:text-foreground">
                        Point Claude Code at Bilis &rarr;
                    </a>
                </div>

                <div class="lg:col-span-7 lg:col-start-6">
                    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h3 class="text-lg font-semibold tracking-tight">One key, one project</h3>
                            <p class="mt-2 max-w-xl text-sm leading-relaxed text-muted-foreground">
                                Revocable in a click, and useless for anything but writing telemetry to
                                that project. Access stays under your thumb.
                            </p>
                        </div>
                    </div>

                    <figure class="overflow-hidden rounded-lg border border-border bg-background">
                        <picture>
                            <source media="(prefers-color-scheme: dark)" srcset="/screenshot-project-dark.png">
                            <img src="/screenshot-project-light.png"
                                 alt="A Bilis project's API keys panel, showing three issued keys with their creation and last-used dates."
                                 width="1440"
                                 height="900"
                                 loading="lazy"
                                 class="h-[280px] w-full object-cover object-top">
                        </picture>
                    </figure>
                </div>
            </div>
        </div>
    </section>

    {{-- The position is a manifesto on the left and a ruled proof ledger on
         the right — not three interchangeable feature cards. --}}
    <section id="why" data-marketing-section class="mx-auto max-w-5xl scroll-mt-28 px-6 py-20 sm:scroll-mt-16 sm:py-28">
        <div class="grid gap-12 lg:grid-cols-12 lg:items-start">
            <div class="lg:col-span-6">
                <h2 class="text-3xl leading-tight font-semibold tracking-[-0.035em] text-balance sm:text-5xl">
                    Keep the data.<br>
                    Lose the telemetry tax.
                </h2>
                <p class="mt-6 max-w-md text-sm leading-relaxed text-muted-foreground">
                    Bilis is built for the team that owns the box, reads its own incidents, and does
                    not want observability to become another platform to operate.
                </p>
            </div>

            <dl class="divide-y divide-border border-y border-border lg:col-span-5 lg:col-start-8">
                <div class="py-6 first:pt-0 lg:first:pt-6">
                    <dt class="text-base font-semibold tracking-tight">Your data never leaves the building</dt>
                    <dd class="mt-2 text-sm leading-relaxed text-muted-foreground">
                        You own the box, so you own the logs — all of them, for as long as your disk
                        allows. Retention costs storage you already pay for, not a metered bill that
                        punishes a noisy deploy.
                    </dd>
                </div>
                <div class="py-6">
                    <dt class="text-base font-semibold tracking-tight">Small enough to run yourself</dt>
                    <dd class="mt-2 text-sm leading-relaxed text-muted-foreground">
                        One app and one database is the entire deployment — a stack you can hold in
                        your head, deploy in an afternoon, and keep healthy without a platform team.
                    </dd>
                </div>
                <div class="py-6 last:pb-0 lg:last:pb-6">
                    <dt class="text-base font-semibold tracking-tight">Open, so you're never stuck</dt>
                    <dd class="mt-2 text-sm leading-relaxed text-muted-foreground">
                        Standard OpenTelemetry in, standard tables underneath. Your scripts, queries
                        and AI tools work on the data today, and walking away is a SELECT, not a migration.
                        The code is <a href="{{ config('bilis.github_url') }}"
                                       class="underline underline-offset-4 hover:text-foreground"
                                       target="_blank" rel="noopener noreferrer">public</a>.
                    </dd>
                </div>
            </dl>
        </div>
    </section>

    {{-- A quieter future-facing band after the high-contrast manifesto. --}}
    <section id="direction" data-marketing-section class="scroll-mt-28 border-y border-border bg-card sm:scroll-mt-16">
        <div class="mx-auto max-w-5xl px-6 py-20 sm:py-28">
            <div class="grid gap-8 sm:grid-cols-12 sm:items-end">
                <h2 class="text-3xl leading-tight font-semibold tracking-[-0.035em] text-balance sm:col-span-7 sm:text-5xl">
                    Logs. Traces.<br>
                    Then software that acts.
                </h2>
                <p class="text-sm leading-relaxed text-muted-foreground sm:col-span-4 sm:col-start-9">
                    Bilis keeps two signals on your box today, linked so each explains the other. The
                    direction is metrics and AI that helps fix what the signals reveal. Self-hosting
                    stays first-class through all of it.
                </p>
            </div>

            <div class="mt-14 grid gap-12 sm:grid-cols-2">
                <div>
                    <h3 class="text-sm font-semibold">In the product today</h3>
                    <ul class="mt-4 divide-y divide-border border-y border-border">
                        @foreach ([
                            'OTLP, plain JSON, and Sentry-compatible ingest',
                            'Fast full-text search across every log body',
                            'Distributed traces drawn as a span waterfall',
                            'Logs and traces linked in both directions',
                            'Live tail, teams, projects, and revocable keys',
                            'Open, portable telemetry tables',
                        ] as $item)
                            <li class="py-3 text-sm">{{ $item }}</li>
                        @endforeach
                    </ul>
                </div>

                <div>
                    <h3 class="text-sm font-semibold text-muted-foreground">Where it is heading</h3>
                    <ul class="mt-4 divide-y divide-border border-y border-border text-muted-foreground">
                        @foreach ([
                            'Metrics on the same box and open standards',
                            'Alerting, so the stack tells you when to look',
                            'Dashboards and saved searches',
                            'AI that spots, explains, and helps fix errors',
                        ] as $item)
                            <li class="py-3 text-sm">{{ $item }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-4 text-xs leading-relaxed text-muted-foreground">
                        eBPF collection, S3 tiering, and replication stay a bigger platform's job.
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- Closing --}}
    <section id="start" data-marketing-section class="scroll-mt-28 sm:scroll-mt-16">
        <div class="mx-auto grid max-w-5xl gap-10 px-6 py-20 sm:py-28 lg:grid-cols-12 lg:items-start">
            <h2 class="text-3xl leading-tight font-semibold tracking-[-0.035em] text-balance sm:text-5xl lg:col-span-7">
                Bilis is early.<br>
                The product is not hypothetical.
            </h2>

            <div class="lg:col-span-4 lg:col-start-9">
                <p class="text-sm leading-relaxed text-muted-foreground">
                    There is no hosted tier yet and nothing to buy. Run the logs and traces product on
                    your own infrastructure today. If a hosted option lands later, self-hosting stays
                    the first-class way to use Bilis.
                </p>
                <p class="mt-4 text-sm leading-relaxed text-muted-foreground">
                    The whole thing is open on GitHub, so you can read exactly what it does with your
                    telemetry before you point anything at it.
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
        </div>
    </section>

    @push('scripts')
        {{-- The marketing bundle — shader, live tail, copy buttons. Still no Inertia. --}}
        @vite('resources/js/marketing/marketing.ts')
    @endpush
</x-layouts.marketing>
