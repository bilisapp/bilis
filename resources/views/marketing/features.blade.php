<x-layouts.marketing
    title="Features"
    description="Everything Bilis does: OTLP/HTTP ingest, an OTel-compatible ClickHouse table, and a log viewer built for finding one line among millions."
    current="features"
>
    {{-- The page a sceptical engineer reads after the landing page.

         No shader band here: the hero's job on the landing page is to make a
         claim, and this page's job is to substantiate one. It opens on the
         page ground and gets straight to the ledger, using the same grammar —
         numbered mono section labels, hairline dividers, alternating card
         bands — so the two read as one site. --}}
    <section class="mx-auto max-w-5xl px-6 pt-16 pb-12 sm:pt-20">
        <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Features</p>

        <h1 class="mt-4 max-w-3xl text-3xl leading-tight font-semibold tracking-tight sm:text-4xl">
            Everything Bilis does, and nothing it does not.
        </h1>

        <p class="mt-5 max-w-2xl text-sm leading-relaxed text-muted-foreground sm:text-base">
            Bilis is one Laravel app and one ClickHouse database. Logs come in over OTLP/HTTP,
            land in a table whose columns belong to the OpenTelemetry project rather than to us,
            and come back out through a viewer built for one question: which line was it.
            Everything below is in the product today — where something is missing, it says so.
        </p>

        {{-- A contents strip. The page is long on purpose; the reader should
             be able to skip to the part they came for. --}}
        <nav aria-label="On this page"
             class="mt-8 flex flex-wrap gap-x-6 gap-y-2 border-t border-border pt-5 font-mono text-[11px] tracking-[0.14em] text-muted-foreground uppercase">
            @foreach ([
                '01' => ['label' => 'Ingest', 'href' => '#ingest'],
                '02' => ['label' => 'Storage', 'href' => '#storage'],
                '03' => ['label' => 'The viewer', 'href' => '#viewer'],
                '04' => ['label' => 'Dashboard', 'href' => '#dashboard'],
                '05' => ['label' => 'Projects and keys', 'href' => '#projects'],
                '06' => ['label' => 'Running it', 'href' => '#running'],
                '07' => ['label' => 'Honest limits', 'href' => '#limits'],
                '08' => ['label' => 'Not in the product', 'href' => '#scope'],
            ] as $number => $entry)
                <a href="{{ $entry['href'] }}"
                   class="flex items-center gap-2 transition-colors hover:text-foreground">
                    <span class="text-foreground/40">{{ $number }}</span>
                    {{ $entry['label'] }}
                </a>
            @endforeach
        </nav>
    </section>

    {{-- 01 — Ingest --}}
    <section id="ingest"
             class="scroll-mt-20 border-y border-border bg-card">
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            @include('marketing.partials.section-label', ['number' => '01', 'label' => 'Ingest'])

            <h2 class="mt-4 text-xl font-semibold tracking-tight">Three ways in, one contract</h2>
            <p class="mt-3 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                Whatever your application already speaks, there is a path that does not require
                rewriting it. All three authenticate with the same project API key, write into the
                same table, and answer the same way when something is wrong with the payload.
            </p>

            @include('marketing.features.endpoints')

            <div class="mt-10 grid gap-8 sm:grid-cols-2">
                <div>
                    <h3 class="text-base font-semibold tracking-tight">OTLP/HTTP, JSON and protobuf</h3>
                    <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                        Standard OTLP, so any exporter you already run works unchanged. Protobuf matters
                        because most OTel SDKs cannot speak OTLP/JSON at all — the Go, Java, .NET and Rust
                        HTTP exporters are protobuf-only. Bilis decodes it
                        <strong class="font-medium text-foreground">in-process, in pure PHP</strong>: no
                        composer package and no <code class="font-mono text-xs">ext-protobuf</code>. Unknown
                        fields are skipped rather than refused, so a newer collector keeps working against an
                        older Bilis. Because it parses untrusted binary it has an off switch,
                        <code class="font-mono text-xs">BILIS_OTLP_PROTOBUF=false</code>.
                    </p>
                </div>

                <div>
                    <h3 class="text-base font-semibold tracking-tight">A plain JSON endpoint, and a Sentry-shaped
                        one</h3>
                    <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                        If you would rather not run a collector,
                        <code class="font-mono text-xs">/api/v1/ingest</code> takes one JSON object or a list
                        of them; only the message is required. And if your application already reports
                        exceptions through a Sentry SDK, Bilis implements the ingest protocol those SDKs
                        speak — point the DSN here and the exceptions land as
                        <code class="font-mono text-xs">ERROR</code>
                        records beside the logs that explain them. That is protocol compatibility for
                        ingestion, not error tracking: there is no issue list, no grouping, no resolve button.
                    </p>
                </div>
            </div>

            {{-- The round trip, not a snippet: the same shape the landing page
                 uses, showing the never-blame-the-client contract rather than
                 asserting it. --}}
            <div class="mt-10 overflow-hidden rounded-xl border border-border bg-background">
                <div class="flex items-center justify-between gap-4 border-b border-border px-4 py-2">
                    <span class="font-mono text-[11px] tracking-[0.14em] text-muted-foreground uppercase">Request</span>

                    <button type="button"
                            hidden
                            data-copy="features-ingest-request"
                            class="-mr-1.5 rounded px-1.5 py-0.5 font-mono text-[11px] tracking-[0.14em] text-muted-foreground uppercase transition-colors hover:bg-accent hover:text-accent-foreground">
                        <span data-copy-idle>Copy</span>
                        <span data-copy-done
                              hidden
                              class="text-severity-debug">Copied</span>
                    </button>
                </div>

                <div class="overflow-x-auto px-4 py-4">
<pre id="features-ingest-request"
     class="font-mono text-xs leading-relaxed"
     data-copy-text='curl -X POST https://bilis.example.com/api/v1/ingest -H "Authorization: Bearer bilis_KEY" -H "Content-Type: application/json" -d "[{\"message\":\"Card declined for order 41902\",\"level\":\"error\",\"service\":\"checkout\"},{\"message\":\"\"}]"'><span class="text-muted-foreground"># Two records. One of them has no usable message.</span>
curl -X POST https://bilis.example.com/api/v1/ingest \
  -H <span class="text-severity-debug">"Authorization: Bearer bilis_&hellip;"</span> \
  -H <span class="text-severity-debug">"Content-Type: application/json"</span> \
  -d '<span class="text-severity-debug">[{"message":"Card declined for order 41902","level":"error","service":"checkout"},{"message":""}]</span>'</pre>
                </div>

                <div class="border-t border-border px-4 py-3">
                    <span class="font-mono text-[11px] tracking-[0.14em] text-muted-foreground uppercase">
                        Response <span class="text-severity-debug">202 Accepted</span>
                    </span>
                    <pre class="mt-2 overflow-x-auto font-mono text-xs leading-relaxed">{"accepted":<span class="text-severity-info">1</span>,"skipped":<span class="text-severity-info">1</span>}</pre>
                </div>
            </div>

            <div class="mt-8 grid gap-x-12 gap-y-3 sm:grid-cols-2">
                <ul class="divide-y divide-border border-t border-border">
                    @foreach ([
                        'Ingest never returns 400 for a bad payload — bad records are skipped and counted',
                        'OTLP reports the skipped ones through partialSuccess, still 200',
                        'A storage failure is 503 with Retry-After, because exporters retry 5xx and drop 4xx',
                    ] as $item)
                        <li class="flex items-baseline gap-3 py-2.5 text-sm">
                            <span class="font-mono text-severity-debug"
                                  aria-hidden="true">+</span>
                            <span>{{ $item }}</span>
                        </li>
                    @endforeach
                </ul>

                <ul class="divide-y divide-border border-t border-border">
                    @foreach ([
                        'gzip and deflate bodies are inflated; a decompressed body is capped at 32 MB',
                        'Throttled per key: 1,200 requests a minute, 60 without a key — both configurable',
                        'Retries are idempotent: an identical re-sent batch is deduplicated by ClickHouse',
                    ] as $item)
                        <li class="flex items-baseline gap-3 py-2.5 text-sm">
                            <span class="font-mono text-severity-debug"
                                  aria-hidden="true">+</span>
                            <span>{{ $item }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>

            <p class="mt-6 text-sm text-muted-foreground">
                <a href="{{ route('docs.show', ['section' => 'ingestion', 'page' => 'endpoints']) }}"
                   class="underline underline-offset-2 hover:text-foreground">The full request and response contract
                    &rarr;</a>
            </p>
        </div>
    </section>

    {{-- 02 — Storage --}}
    <section id="storage"
             class="scroll-mt-20">
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            @include('marketing.partials.section-label', ['number' => '02', 'label' => 'Storage'])

            <h2 class="mt-4 text-xl font-semibold tracking-tight">An OpenTelemetry table, not a private format</h2>
            <p class="mt-3 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                Logs live in one ClickHouse table, <code class="font-mono text-xs">otel_logs</code>. Its column
                names and types are the ones the upstream OpenTelemetry ClickHouse exporter writes, pinned to a
                specific collector tag and re-diffed on every upgrade. That is the whole portability story:
                the rows are readable by anything that already understands that schema, and getting your data
                out is a <code class="font-mono text-xs">SELECT</code>, not a migration project.
            </p>

            <div class="mt-8 grid gap-x-12 gap-y-10 sm:grid-cols-2">
                <div>
                    <p class="font-mono text-[11px] tracking-[0.18em] text-muted-foreground uppercase">Theirs, and left
                        alone</p>

                    <ul class="mt-3 divide-y divide-border border-t border-border">
                        @foreach ([
                            'Timestamp, TraceId, SpanId, TraceFlags',
                            'SeverityText, SeverityNumber, ServiceName, Body',
                            'ResourceAttributes, ScopeAttributes, LogAttributes',
                            'ScopeName, ScopeVersion, EventName, schema URLs',
                        ] as $item)
                            <li class="flex items-baseline gap-3 py-2.5 font-mono text-xs">
                                <span class="text-severity-debug"
                                      aria-hidden="true">+</span>
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div>
                    <p class="font-mono text-[11px] tracking-[0.18em] text-muted-foreground uppercase">Ours, and
                        documented</p>

                    <ul class="mt-3 divide-y divide-border border-t border-border">
                        @foreach ([
                            'A ProjectId column, written only from the authenticated key',
                            'ORDER BY (ProjectId, Timestamp, ServiceName)',
                            'PARTITION BY day, so expiry is a partition drop rather than a rewrite',
                            'A 30-day TTL by default, and the indexes search runs on',
                        ] as $item)
                            <li class="flex items-baseline gap-3 py-2.5 text-sm">
                                <span class="font-mono text-severity-debug"
                                      aria-hidden="true">+</span>
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>

            <p class="mt-8 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                The project a line belongs to is decided by the API key that carried it and never by anything
                in the payload — a resource attribute named <code class="font-mono text-xs">project.id</code>
                is just an attribute. Writes use ClickHouse's asynchronous insert path, which is why a success
                means <em>queued</em> rather than durable; that trade is stated plainly further down.
            </p>

            <p class="mt-4 text-sm text-muted-foreground">
                Bilis talks to ClickHouse over its HTTP interface, so there is no driver and no PHP extension
                to install.
            </p>
        </div>
    </section>

    {{-- 03 — The viewer --}}
    <section id="viewer"
             class="scroll-mt-20 border-y border-border bg-card">
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            @include('marketing.partials.section-label', ['number' => '03', 'label' => 'The viewer'])

            <h2 class="mt-4 text-xl font-semibold tracking-tight">Built for finding one line among millions</h2>
            <p class="mt-3 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                Every query the viewer makes is the same shape: a project, a time range, and an index the
                sort key was designed for. There is no query language to learn, and no way to write a search
                that quietly scans the whole table.
            </p>

            <div class="mt-8 grid gap-x-12 gap-y-8 sm:grid-cols-2">
                <div>
                    <h3 class="text-base font-semibold tracking-tight">Filters</h3>
                    <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                        A time range, one project, one service, and any combination of the six severities —
                        trace, debug, info, warn, error, fatal. Filters live in the URL, so a search is a link
                        you can paste into an incident channel.
                    </p>
                </div>

                <div>
                    <h3 class="text-base font-semibold tracking-tight">Full-text search over bodies</h3>
                    <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                        Backed by a token bloom filter index on the log body. It matches whole tokens,
                        case-insensitively — <span class="text-foreground">not substrings</span>, which is the
                        one thing worth knowing before you type. Searching
                        <code class="font-mono text-xs">timeout</code>
                        finds the line; searching <code class="font-mono text-xs">imeou</code> does not.
                    </p>
                </div>

                <div>
                    <h3 class="text-base font-semibold tracking-tight">Live tail</h3>
                    <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                        Leave it open during a deploy. New lines arrive at the top under the filters you
                        already set, and scrolling away pauses the stream rather than fighting you for the
                        scroll position.
                    </p>
                </div>

                <div>
                    <h3 class="text-base font-semibold tracking-tight">The line itself</h3>
                    <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                        Rows are set in a mono face with severity as the only colour on the page, and expand
                        to the full body plus every resource, scope and log attribute that arrived with it —
                        including trace and span ids. Pages are fetched a hundred rows at a time by timestamp
                        cursor, not by offset.
                    </p>
                </div>
            </div>

            <div class="mt-10 overflow-hidden rounded-xl border border-border bg-background">
                <picture>
                    <source media="(prefers-color-scheme: dark)"
                            srcset="/screenshot-logs-dark.png">
                    <img src="/screenshot-logs-light.png"
                         alt="The Bilis log viewer: time-range, project, service and severity filters above a scrolling stream of timestamped, severity-coloured log lines."
                         width="1440"
                         height="900"
                         loading="lazy">
                </picture>
            </div>
        </div>
    </section>

    {{-- 04 — Dashboard --}}
    <section id="dashboard"
             class="scroll-mt-20">
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            @include('marketing.partials.section-label', ['number' => '04', 'label' => 'Dashboard'])

            <h2 class="mt-4 text-xl font-semibold tracking-tight">One overview, built in and not configurable</h2>
            <p class="mt-3 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                There is exactly one dashboard and you cannot build another. It answers the questions you
                would have built the first one to answer, and then gets out of the way — the viewer is where
                the actual work happens.
            </p>

            <ul class="mt-8 divide-y divide-border border-t border-b border-border">
                @foreach ([
                    'Volume and errors' => 'Lines and error-level lines over the last day, against the day before, with the change stated as a percentage — or as nothing at all when there is no prior day to compare against.',
                    'Recurring failures' => 'The error bodies that came back most often, so the loudest thing is at the top rather than buried a thousand lines down.',
                    'Service liveness' => 'Every service that has reported, when it was last seen, and a sparkline of its recent volume and errors. A service that has gone quiet is marked quiet, because silence is the failure mode a log viewer hides best.',
                    'Retained storage' => 'Rows and bytes on disk per project, largest first, read from ClickHouse itself rather than estimated.',
                    'Ingest budget' => 'What each API key has spent against the rate limit in the current minute — the limiter\'s own rolling counter, labelled as such and never charted over time.',
                ] as $title => $body)
                    <li class="grid gap-1 py-4 sm:grid-cols-[13rem_1fr] sm:gap-6">
                        <span class="text-sm font-medium">{{ $title }}</span>
                        <span class="text-sm leading-relaxed text-muted-foreground">{{ $body }}</span>
                    </li>
                @endforeach
            </ul>

            <p class="mt-6 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                When ClickHouse cannot be reached the cards say so rather than showing zeros, which is a
                distinction that matters at three in the morning.
            </p>
        </div>
    </section>

    {{-- 05 — Projects, teams and keys --}}
    <section id="projects"
             class="scroll-mt-20 border-y border-border bg-card">
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            @include('marketing.partials.section-label', ['number' => '05', 'label' => 'Projects and keys'])

            <h2 class="mt-4 text-xl font-semibold tracking-tight">A key writes to one project, and can do nothing
                else</h2>
            <p class="mt-3 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                Projects belong to a team, and everything you look at in the app is scoped to the team you
                are in. Each project issues its own API keys, and a key is one credential with two halves —
                issued together, revoked together, sharing one rate limit.
            </p>

            <div class="mt-8 grid gap-x-12 gap-y-8 sm:grid-cols-2">
                <div class="rounded-xl border border-border bg-background p-5">
                    <p class="font-mono text-[11px] tracking-[0.18em] text-muted-foreground uppercase">Secret half</p>
                    <p class="mt-2 font-mono text-sm">bilis_&hellip;</p>
                    <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                        For collectors, shippers, anything that can set a header. Only a sha256 of it is
                        stored, so the plaintext is shown exactly once and there is no way to read it back.
                    </p>
                </div>

                <div class="rounded-xl border border-border bg-background p-5">
                    <p class="font-mono text-[11px] tracking-[0.18em] text-muted-foreground uppercase">Public half</p>
                    <p class="mt-2 font-mono text-sm">bilis_pk_&hellip;</p>
                    <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                        Stored in plaintext and always readable on the project page, because it is built into
                        a DSN — a credential in a URL is already disclosed, and hashing it would only make the
                        URL unrecoverable.
                    </p>
                </div>
            </div>

            <p class="mt-8 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                Both halves authorise exactly one thing: writing logs into their own project. Neither can read
                a log line, list a project, or touch anything else — so a leaked public key costs you junk in
                one project's stream, not access to your data. For anything running in a browser there is a
                second lock that is not on the key at all: a per-project origin allow list, with an empty list
                meaning no page may post.
            </p>

            <p class="mt-6 text-sm text-muted-foreground">
                <a href="{{ route('docs.show', ['section' => 'ingestion', 'page' => 'api-keys']) }}"
                   class="underline underline-offset-2 hover:text-foreground">How keys are issued and revoked &rarr;</a>
            </p>
        </div>
    </section>

    {{-- 06 — Running it --}}
    <section id="running"
             class="scroll-mt-20">
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            @include('marketing.partials.section-label', ['number' => '06', 'label' => 'Running it'])

            <h2 class="mt-4 text-xl font-semibold tracking-tight">One app, one database, your box</h2>
            <p class="mt-3 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                Bilis is self-hosted, and that is not a fallback — there is no hosted tier, nothing to buy,
                and no per-gigabyte bill at the end of a noisy month. What retention costs you is disk you
                already pay for.
            </p>

            <div class="mt-8 overflow-hidden rounded-xl border border-border bg-card">
                <div class="flex items-center justify-between gap-4 border-b border-border px-4 py-2">
                    <span class="font-mono text-[11px] tracking-[0.14em] text-muted-foreground uppercase">The entire configuration</span>

                    <button type="button"
                            hidden
                            data-copy="features-clickhouse-env"
                            class="-mr-1.5 rounded px-1.5 py-0.5 font-mono text-[11px] tracking-[0.14em] text-muted-foreground uppercase transition-colors hover:bg-accent hover:text-accent-foreground">
                        <span data-copy-idle>Copy</span>
                        <span data-copy-done
                              hidden
                              class="text-severity-debug">Copied</span>
                    </button>
                </div>

                <div class="overflow-x-auto px-4 py-4">
<pre id="features-clickhouse-env"
     class="font-mono text-xs leading-relaxed"
     data-copy-text="CLICKHOUSE_SCHEME=http
CLICKHOUSE_HOST=127.0.0.1
CLICKHOUSE_PORT=8123
CLICKHOUSE_DATABASE=bilis
CLICKHOUSE_USERNAME=default
CLICKHOUSE_PASSWORD=

php artisan clickhouse:migrate">CLICKHOUSE_SCHEME=<span class="text-severity-debug">http</span>
CLICKHOUSE_HOST=<span class="text-severity-debug">127.0.0.1</span>
CLICKHOUSE_PORT=<span class="text-severity-debug">8123</span>
CLICKHOUSE_DATABASE=<span class="text-severity-debug">bilis</span>
CLICKHOUSE_USERNAME=<span class="text-severity-debug">default</span>
CLICKHOUSE_PASSWORD=

<span class="text-muted-foreground"># Create or update the log table. Idempotent, so it is safe on every deploy.</span>
php artisan clickhouse:migrate</pre>
                </div>
            </div>

            <div class="mt-8 grid gap-8 sm:grid-cols-3">
                <div>
                    <h3 class="text-base font-semibold tracking-tight">No stack to operate</h3>
                    <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                        A Laravel app and a ClickHouse. No Grafana, no Loki, no agent mesh, and no message
                        broker between them. Operational simplicity is the product here, not a side effect.
                    </p>
                </div>
                <div>
                    <h3 class="text-base font-semibold tracking-tight">Nothing exotic to install</h3>
                    <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                        ClickHouse over its HTTP interface, so no driver or PHP extension. Protobuf decoded in
                        pure PHP for the same reason. Timeouts are deliberately short so an overloaded database
                        fails fast into a retryable 503 instead of holding a worker.
                    </p>
                </div>
                <div>
                    <h3 class="text-base font-semibold tracking-tight">Readable before you trust it</h3>
                    <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                        The whole thing is
                        <a href="{{ config('bilis.github_url') }}"
                           class="underline underline-offset-2 hover:text-foreground"
                           target="_blank"
                           rel="noopener noreferrer">open on GitHub</a>, including the schema
                        document that governs the table. You can read exactly what happens to a log line before
                        you point anything at it.
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- 07 — Honest limits --}}
    <section id="limits"
             class="scroll-mt-20 border-y border-border bg-card">
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            @include('marketing.partials.section-label', ['number' => '07', 'label' => 'Honest limits'])

            <h2 class="mt-4 text-xl font-semibold tracking-tight">The parts a demo would leave out</h2>
            <p class="mt-3 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                These are properties of the design, not bugs waiting on a release. They are the things you
                would find out in week two, so they are here in minute one.
            </p>

            <ul class="mt-8 divide-y divide-border border-t border-b border-border">
                @foreach ([
                    'An acknowledgement is not durability' => 'Rows are inserted with async_insert=1 and wait_for_async_insert=0, so a 200 or 202 means the batch reached the insert buffer. A crash in the window before the flush loses that buffer. The trade is throughput, because small frequent inserts are exactly what ClickHouse handles badly without it. If a line matters more than that, keep a local copy too.',
                    'Retention is one table-wide TTL' => 'Thirty days by default, dropped a whole partition at a time. It is a property of the ClickHouse table, not a per-project setting.',
                    'Search is token-based' => 'Whole tokens, case-insensitively, over the log body. Not substrings and not regular expressions.',
                    'One node, and no replication' => 'Plain MergeTree on a single box. Replication needs Keeper, and a replicated table with unreachable Keeper goes read-only — a failure mode without redundancy on one machine. Replication would not be a backup anyway; back the table up to object storage from day one.',
                    'Volume control belongs to the sender' => 'Bilis stores what arrives. There is no server-side sampling and no ingest-side downsampling, and none is planned — dropping data you deliberately sent is a surprising way to protect a disk you own. Filter and sample in the SDK or collector instead.',
                    'The rate limit shapes requests, not volume' => 'It counts HTTP requests per key, so a well-batched project can still fill the disk without ever seeing a 429. There is no per-project ingest quota yet.',
                ] as $title => $body)
                    <li class="grid gap-1 py-4 sm:grid-cols-[15rem_1fr] sm:gap-6">
                        <span class="text-sm font-medium">{{ $title }}</span>
                        <span class="text-sm leading-relaxed text-muted-foreground">{{ $body }}</span>
                    </li>
                @endforeach
            </ul>

            <p class="mt-6 text-sm text-muted-foreground">
                <a href="{{ route('docs.show', ['section' => 'reference', 'page' => 'limits-and-behavior']) }}"
                   class="underline underline-offset-2 hover:text-foreground">Limits and behavior, including sizing
                    &rarr;</a>
            </p>
        </div>
    </section>

    {{-- 08 — Scope --}}
    <section id="scope"
             class="scroll-mt-20">
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            @include('marketing.partials.section-label', ['number' => '08', 'label' => 'Not in the product'])

            <h2 class="mt-4 text-xl font-semibold tracking-tight">What to use instead</h2>
            <p class="mt-3 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                Being narrow is the point, so the omissions get named and pointed somewhere rather than left
                to be discovered. None of these is a roadmap item.
            </p>

            <ul class="mt-8 divide-y divide-border border-t border-b border-border">
                @foreach ([
                    ['Traces and metrics', 'A tracing backend. Bilis stores the trace and span ids that arrive on a log line, so the two can be correlated — it just does not store the spans.'],
                    ['Alerting and on-call', 'Whatever already pages you. Nothing in Bilis will wake anybody up.'],
                    ['Error tracking', 'Sentry itself. The envelope endpoint accepts what its SDKs send, but there is no issue list, no grouping and no resolve button.'],
                    ['User-defined dashboards and saved searches', 'The built-in overview, and a URL — every filter combination is a link you can bookmark.'],
                    ['OTLP over gRPC', 'A Collector, which already bridges that hop. PHP is a poor gRPC server and this will not change.'],
                    ['eBPF collection, S3 tiering, replication', 'A larger platform. All three add operating surface a one-box deployment cannot pay for.'],
                    ['Billing and a hosted tier', 'Nothing — there is no hosted Bilis to buy. Self-hosting is the way to use it.'],
                ] as [$item, $instead])
                    <li class="grid gap-1 py-4 sm:grid-cols-[15rem_1fr] sm:gap-6">
                        <span class="flex items-baseline gap-3 text-sm text-muted-foreground">
                            <span class="font-mono"
                                  aria-hidden="true">&minus;</span>
                            <span>{{ $item }}</span>
                        </span>
                        <span class="text-sm leading-relaxed text-muted-foreground">{{ $instead }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- Closing --}}
    <section class="border-t border-border bg-card">
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            <h2 class="text-xl font-semibold tracking-tight">Point something at it</h2>
            <p class="mt-3 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                A project, a key, and one line of curl is the whole first run. The quickstart takes a few
                minutes and does not ask you to install a collector first.
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

                <a href="{{ route('docs.show', ['section' => 'getting-started', 'page' => 'quickstart']) }}"
                   class="rounded-md border border-border px-5 py-2.5 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground">
                    Read the quickstart
                </a>

                <a href="{{ config('bilis.github_url') }}"
                   class="flex items-center gap-2 rounded-md border border-border px-5 py-2.5 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground"
                   target="_blank"
                   rel="noopener noreferrer">
                    <x-icons.github class="size-4" />
                    Read the source
                </a>
            </div>
        </div>
    </section>

    @push('scripts')
        {{-- The marketing bundle, for the copy buttons. The shader and live-tail
             modules find no hook on this page and no-op. Still no Inertia. --}}
        @vite('resources/js/marketing/marketing.ts')
    @endpush
</x-layouts.marketing>
