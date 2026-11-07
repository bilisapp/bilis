@php
    /** @var list<\App\Services\Tools\LogVolumeEstimate> $examples */

    $money = fn (float $value): string => '$'.number_format($value, $value < 100 ? 2 : 0);
    $gigabytes = fn (float $value): string => $value >= 1000
        ? number_format($value / 1000, 1).' TB'
        : number_format($value, $value < 10 ? 1 : 0).' GB';

    $rows = [
        ['Where it runs', 'On your own server, or the hosted Free plan at bilis.app.', 'Datadog\'s cloud only; the Agent runs on your hosts and ships to it.'],
        ['How log pricing works', 'Self-hosted: whatever the server costs. No per-GB or per-event line.', 'Per GB ingested plus per million events indexed, by retention period.'],
        ['Signals', 'Logs, traces and metrics.', 'Logs, APM traces, metrics, and much more: RUM, synthetics, security, profiling.'],
        ['How data gets in', 'OpenTelemetry over HTTP (JSON or protobuf), a Monolog channel, a one-line Linux agent.', 'The Datadog Agent and its integrations; OpenTelemetry is accepted too.'],
        ['Storage you can query', 'OpenTelemetry\'s own table layout in ClickHouse — readable with plain SQL.', 'A proprietary store, read through Datadog\'s UI and API.'],
        ['AI agents', 'Built-in MCP server: read-only, OAuth with a consent screen, queries run on your box.', 'An MCP server too, reading data held in Datadog\'s cloud.'],
        ['Alerting', 'Not yet — keep whatever pages you today.', 'Mature monitors, notifications and on-call integrations.'],
        ['Dashboards', 'A built-in overview and curated host charts; no dashboard builder.', 'A full dashboard builder.'],
        ['Scale', 'One node, no replication. Sized for one team\'s services.', 'Built for very large fleets.'],
        ['Source', 'Source-available under FSL-1.1-ALv2; each release becomes Apache 2.0 after two years.', 'Closed source.'],
    ];

    $faq = [
        ['Is Bilis open source?', 'It is source-available under the Functional Source License (FSL-1.1-ALv2): you can read, run and modify it, self-hosting included, and each release converts to Apache 2.0 two years after it ships. It is not an OSI-approved licence until that conversion, so we do not call it open source.'],
        ['Can I run Bilis and Datadog side by side?', 'Yes. An OpenTelemetry Collector can export the same logs, traces and metrics to both, so you can compare them on real traffic before moving anything.'],
        ['Does Bilis replace all of Datadog?', 'No. It covers logs, traces and metrics for a team\'s own services. If you rely on Datadog for alerting, real-user monitoring, synthetics or security products, Bilis does not replace those today.'],
        ['How much disk does self-hosting take?', 'Raw log volume × retention ÷ compression. Columnar storage commonly compresses logs 5–15×; the calculator works it out for your rate.'],
    ];

    $structuredData = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array_map(fn (array $item): array => [
            '@type' => 'Question',
            'name' => $item[0],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item[1]],
        ], $faq),
    ];
@endphp

<x-layouts.marketing title="Bilis vs Datadog: a self-hosted alternative"
                     description="Bilis vs Datadog for logs, traces and metrics: self-hosted on your own box with no per-GB bill, an MCP server for coding agents, and the honest limits."
                     current="compare">
    <script type="application/ld+json"
            nonce="{{ $cspNonce ?? '' }}">{!! json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>

    <section class="mx-auto max-w-content px-6 pt-16 pb-12 sm:pt-20">
        <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Bilis vs Datadog</p>

        <h1 class="mt-4 max-w-3xl text-3xl leading-tight font-semibold tracking-tight sm:text-4xl">
            A self-hosted alternative to Datadog for logs, traces and metrics
        </h1>

        <p class="mt-5 max-w-2xl text-sm leading-relaxed text-muted-foreground sm:text-base">
            Datadog does far more than Bilis, and bills by the gigabyte for it. Bilis does less — logs, traces and
            metrics for your own services — on a server you own, with no per-GB line, and with an MCP server so your
            coding agent can read the same data you do. This page is the honest version of that trade.
        </p>
    </section>

    {{-- 01 — The short answer --}}
    <section class="border-y border-border bg-card">
        <div class="mx-auto max-w-content px-6 py-16 sm:py-20">
            @include('marketing.partials.section-label', ['number' => '01', 'label' => 'The short answer'])

            <div class="mt-8 grid gap-6 lg:grid-cols-2">
                @foreach ([
                    ['Choose Bilis when', [
                        'your log bill grows faster than your traffic, and you sample or drop logs to keep it down;',
                        'your logs carry data that should not leave your infrastructure;',
                        'you want a coding agent reading production telemetry without shipping it to another vendor;',
                        'you already send OpenTelemetry, or are about to.',
                    ]],
                    ['Stay on Datadog when', [
                        'you need alerting, on-call and dashboards you build yourself today;',
                        'you use its other products — real-user monitoring, synthetics, security;',
                        'you run a large fleet that needs replication and horizontal scale;',
                        'nobody on the team wants to operate a server.',
                    ]],
                ] as [$heading, $points])
                    <div class="rounded-xl border border-border bg-background p-6">
                        <h2 class="text-lg font-semibold tracking-tight">{{ $heading }}</h2>
                        <ul class="mt-4 grid gap-2 text-sm leading-relaxed text-muted-foreground">
                            @foreach ($points as $point)
                                <li class="flex gap-3"><span class="font-mono text-foreground/45" aria-hidden="true">&rarr;</span><span>{{ $point }}</span></li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- 02 — Side by side --}}
    <section>
        <div class="mx-auto max-w-content px-6 py-16 sm:py-20">
            @include('marketing.partials.section-label', ['number' => '02', 'label' => 'Side by side'])

            <h2 class="mt-4 text-xl font-semibold tracking-tight">What each one is</h2>

            <div class="mt-8 overflow-x-auto rounded-xl border border-border">
                <table class="w-full min-w-[40rem] text-sm">
                    <thead class="border-b border-border bg-card text-left">
                        <tr>
                            <th class="w-44 px-4 py-3 font-medium text-muted-foreground"><span class="sr-only">Aspect</span></th>
                            <th class="px-4 py-3 font-semibold">Bilis</th>
                            <th class="px-4 py-3 font-semibold">Datadog</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($rows as [$aspect, $bilis, $datadog])
                            <tr class="align-top">
                                <th scope="row" class="px-4 py-3 text-left font-medium">{{ $aspect }}</th>
                                <td class="px-4 py-3 leading-relaxed text-muted-foreground">{{ $bilis }}</td>
                                <td class="px-4 py-3 leading-relaxed text-muted-foreground">{{ $datadog }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    {{-- 03 — Cost --}}
    <section class="border-y border-border bg-card">
        <div class="mx-auto max-w-content px-6 py-16 sm:py-20">
            @include('marketing.partials.section-label', ['number' => '03', 'label' => 'What logs cost'])

            <h2 class="mt-4 text-xl font-semibold tracking-tight">The same logs, priced two ways</h2>
            <p class="mt-3 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                At Datadog's published list price for Log Management — ${{ number_format($examples[0]->ingestPricePerGb, 2) }}
                per ingested GB and ${{ number_format($examples[0]->indexPricePerMillion, 2) }} per million events indexed
                for {{ $examples[0]->retentionDays }} days, billed annually, as checked on 9 October 2026 — against the disk the
                same retention takes self-hosted. {{ $examples[0]->averageEventBytes }}-byte events; negotiated discounts and
                other retention periods change the left column.
            </p>

            <div class="mt-8 overflow-x-auto rounded-xl border border-border bg-background">
                <table class="w-full min-w-[36rem] text-sm">
                    <thead class="border-b border-border text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 font-medium">Volume</th>
                            <th class="px-4 py-3 font-medium">Ingested a month</th>
                            <th class="px-4 py-3 text-right font-medium">Datadog logs, a month</th>
                            <th class="px-4 py-3 text-right font-medium">Bilis, disk for {{ $examples[0]->retentionDays }} days</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border font-mono">
                        @foreach ($examples as $example)
                            <tr>
                                <td class="px-4 py-3">
                                    <a href="{{ route('tools.log-cost', $example->toQuery()) }}"
                                       class="underline underline-offset-4">{{ number_format($example->eventsPerSecond) }} events/s</a>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">{{ $gigabytes($example->gigabytesPerMonth()) }}</td>
                                <td class="px-4 py-3 text-right"
                                    data-test="datadog-cost">{{ $money($example->costPerMonth()) }}</td>
                                <td class="px-4 py-3 text-right text-muted-foreground">{{ $gigabytes($example->diskGigabytes()) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mt-4 text-sm text-muted-foreground">
                Self-hosted cost is the server, not the gigabytes — the disk column assumes a {{ $examples[0]->compressionRatio + 0 }}×
                compression ratio, which you should measure.
                <a href="{{ route('tools.log-cost') }}"
                   class="text-foreground underline underline-offset-4">Run your own numbers &rarr;</a>
            </p>
        </div>
    </section>

    {{-- 04 — What Bilis does not do --}}
    <section>
        <div class="mx-auto max-w-content px-6 py-16 sm:py-20">
            @include('marketing.partials.section-label', ['number' => '04', 'label' => 'Honest limits'])

            <h2 class="mt-4 text-xl font-semibold tracking-tight">What you give up</h2>

            <ul class="mt-8 divide-y divide-border border-y border-border">
                @foreach ([
                    'No alerting yet' => 'It is on the way. Until it lands, keep whatever already pages you.',
                    'One node' => 'No replication and no cluster. Back the data up to object storage from day one.',
                    'One retention period' => 'Thirty days by default for logs, spans and metrics, table-wide rather than per project.',
                    'Token search' => 'Whole words, case-insensitive, over the log body — not substrings or regular expressions.',
                    'OTLP over HTTP only' => 'gRPC senders go through an OpenTelemetry Collector, which bridges that hop.',
                ] as $title => $body)
                    <li class="grid gap-1 py-4 sm:grid-cols-[15rem_1fr] sm:gap-6">
                        <span class="text-sm font-medium">{{ $title }}</span>
                        <span class="text-sm leading-relaxed text-muted-foreground">{{ $body }}</span>
                    </li>
                @endforeach
            </ul>

            <p class="mt-6 text-sm text-muted-foreground">
                <a href="{{ route('features') }}#limits"
                   class="underline underline-offset-2 hover:text-foreground">The full list on the features page &rarr;</a>
            </p>
        </div>
    </section>

    {{-- 05 — Moving over --}}
    <section class="border-y border-border bg-card">
        <div class="mx-auto max-w-content px-6 py-16 sm:py-20">
            @include('marketing.partials.section-label', ['number' => '05', 'label' => 'Trying it'])

            <h2 class="mt-4 text-xl font-semibold tracking-tight">Run both before you move anything</h2>

            <ol class="mt-8 grid gap-6 sm:grid-cols-3">
                @foreach ([
                    ['Get an endpoint', 'Self-host Bilis on one server, or start on the hosted Free plan. Self-hosting has no plan at all and stays free.'],
                    ['Fan out', 'Add Bilis as a second exporter in your OpenTelemetry Collector. Datadog keeps receiving everything it did.'],
                    ['Compare on real traffic', 'Search the same incident in both, connect your coding agent over MCP, and decide with your own data.'],
                ] as $index => [$title, $body])
                    <li class="rounded-xl border border-border bg-background p-6">
                        <span class="font-mono text-xs text-muted-foreground">0{{ $index + 1 }}</span>
                        <h3 class="mt-2 text-sm font-semibold">{{ $title }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-muted-foreground">{{ $body }}</p>
                    </li>
                @endforeach
            </ol>

            <div class="mt-8 flex flex-wrap gap-3">
                <a href="{{ route('register') }}"
                   class="rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90">
                    Start free
                </a>
                <a href="{{ route('docs.show', ['section' => 'ingestion', 'page' => 'endpoints']) }}"
                   class="rounded-md border border-border px-5 py-2.5 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground">
                    OTLP endpoints
                </a>
                <a href="{{ route('features.mcp') }}"
                   class="rounded-md border border-border px-5 py-2.5 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground">
                    The MCP server
                </a>
            </div>
        </div>
    </section>

    {{-- 06 — FAQ --}}
    <section>
        <div class="mx-auto max-w-content px-6 py-16 sm:py-20">
            @include('marketing.partials.section-label', ['number' => '06', 'label' => 'Questions'])

            <dl class="mt-8 divide-y divide-border border-y border-border">
                @foreach ($faq as [$question, $answer])
                    <div class="grid gap-1 py-5 sm:grid-cols-[18rem_1fr] sm:gap-6">
                        <dt class="text-sm font-medium">{{ $question }}</dt>
                        <dd class="text-sm leading-relaxed text-muted-foreground">{{ $answer }}</dd>
                    </div>
                @endforeach
            </dl>

            <p class="mt-8 max-w-2xl text-xs leading-relaxed text-muted-foreground">
                Datadog is a trademark of Datadog, Inc. This comparison is ours, made from public information as of
                October 2026; if something here is out of date, <a href="{{ route('contact.show') }}"
                class="underline underline-offset-2 hover:text-foreground">tell us</a> and we will fix it.
            </p>
        </div>
    </section>
</x-layouts.marketing>
