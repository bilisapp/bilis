@php
    /** @var \App\Services\Tools\LogVolumeEstimate $estimate */

    $gigabytes = fn (float $value): string => $value >= 1000
        ? number_format($value / 1000, 2).' TB'
        : number_format($value, $value < 10 ? 2 : 1).' GB';

    $money = fn (float $value): string => '$'.number_format($value, $value < 100 ? 2 : 0);

    $count = fn (float $value): string => match (true) {
        $value >= 1e9 => number_format($value / 1e9, 2).' billion',
        $value >= 1e6 => number_format($value / 1e6, 1).' million',
        default => number_format($value),
    };

    $fields = [
        ['eps', 'Events per second', 'Average across the day, not the peak.', '0.1', $estimate->eventsPerSecond],
        ['size', 'Average event size (bytes)', 'Uncompressed, as sent — a JSON log line is often 300–1,000.', '1', $estimate->averageEventBytes],
        ['retention', 'Retention (days)', 'How long a line stays searchable.', '1', $estimate->retentionDays],
        ['ingest', 'Price per ingested GB ($)', 'The per-GB part of the bill.', '0.01', $estimate->ingestPricePerGb],
        ['index', 'Price per million indexed events ($)', 'The per-event part, for the retention above.', '0.01', $estimate->indexPricePerMillion],
        ['ratio', 'Compression ratio on disk', 'Columnar storage of logs: commonly 5–15×. Measure yours.', '0.5', $estimate->compressionRatio],
    ];
@endphp

<x-tools.page route="tools.log-cost"
              title="Log volume & cost calculator"
              description="Turn events per second into GB a day, estimate a per-GB log management bill, and see how much disk the same logs need when you self-host."
              heading="Log volume & cost calculator"
              intro="How many gigabytes do your logs come to, what does a per-GB vendor charge for them, and how much disk would the same data take on your own server? Change any number and the answer follows.">
    <form method="GET"
          action="{{ route('tools.log-cost') }}"
          data-tool-form
          class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <div class="grid gap-5 sm:grid-cols-2">
            @foreach ($fields as [$name, $label, $hint, $step, $value])
                <div class="grid content-start gap-2">
                    <label for="cost-{{ $name }}"
                           class="text-sm font-medium">{{ $label }}</label>
                    <input id="cost-{{ $name }}"
                           name="{{ $name }}"
                           type="number"
                           inputmode="decimal"
                           min="0"
                           step="{{ $step }}"
                           value="{{ $value + 0 }}"
                           class="w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-sm">
                    <p class="text-xs leading-relaxed text-muted-foreground">{{ $hint }}</p>
                </div>
            @endforeach

            <div class="sm:col-span-2">
                <button type="submit"
                        data-tool-submit
                        class="rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90">
                    Calculate
                </button>
            </div>
        </div>

        <div data-tool-result
             aria-live="polite"
             class="grid content-start gap-6">
            <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-border bg-border">
                @foreach ([
                    ['Events a day', $count($estimate->eventsPerDay())],
                    ['Events a month', $count($estimate->eventsPerMonth())],
                    ['Ingested a day', $gigabytes($estimate->gigabytesPerDay())],
                    ['Ingested a month', $gigabytes($estimate->gigabytesPerMonth())],
                ] as [$label, $value])
                    <div class="bg-background p-4">
                        <dt class="text-xs text-muted-foreground">{{ $label }}</dt>
                        <dd class="mt-1 font-mono text-lg">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            <div class="rounded-xl border border-border bg-background p-5">
                <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Per-GB SaaS bill</p>
                <p class="mt-2 font-mono text-3xl tracking-tight"
                   data-test="cost-per-month">{{ $money($estimate->costPerMonth()) }}<span class="text-base text-muted-foreground"> / month</span></p>
                <p class="mt-1 font-mono text-sm text-muted-foreground">{{ $money($estimate->costPerYear()) }} a year</p>

                <ul class="mt-4 divide-y divide-border border-t border-border text-sm">
                    <li class="flex justify-between gap-4 py-2">
                        <span class="text-muted-foreground">Ingest — {{ $gigabytes($estimate->gigabytesPerMonth()) }} × {{ $money($estimate->ingestPricePerGb) }}</span>
                        <span class="font-mono">{{ $money($estimate->ingestCostPerMonth()) }}</span>
                    </li>
                    <li class="flex justify-between gap-4 py-2">
                        <span class="text-muted-foreground">Indexing — {{ $count($estimate->eventsPerMonth()) }} events × {{ $money($estimate->indexPricePerMillion) }}/M</span>
                        <span class="font-mono">{{ $money($estimate->indexCostPerMonth()) }}</span>
                    </li>
                </ul>
            </div>

            <div class="rounded-xl border border-border bg-background p-5">
                <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Self-hosted, the same {{ $estimate->retentionDays }} days</p>
                <p class="mt-2 font-mono text-3xl tracking-tight"
                   data-test="disk">{{ $gigabytes($estimate->diskGigabytes()) }}<span class="text-base text-muted-foreground"> on disk</span></p>
                <p class="mt-1 text-sm leading-relaxed text-muted-foreground">
                    {{ $gigabytes($estimate->retainedGigabytes()) }} of raw logs held at once, compressed
                    {{ $estimate->compressionRatio + 0 }}×. The bill is whatever that server costs you — it does not
                    grow per gigabyte.
                </p>
            </div>
        </div>
    </form>

    <x-slot:explainer>
        <h2>How the numbers are worked out</h2>
        <p>
            Events a day is events per second × 86,400. Volume is events × average size, in decimal gigabytes
            (10<sup>9</sup> bytes) because that is the unit vendors bill in. A month is 30 days.
        </p>
        <p>
            The SaaS bill follows the model most log vendors use: a price per gigabyte ingested, plus a price per
            million events kept searchable for a retention period. The defaults are Datadog's published list price
            for Log Management as checked on 9 October 2026 — $0.10 per ingested GB, and $1.70 per million events
            indexed with 15-day retention, billed annually ($2.55 on demand). Longer retention, other tiers and
            negotiated discounts change it; put in your own contract's numbers.
        </p>
        <p>
            The self-hosted figure is disk, not money: the raw volume you would hold at any one time, divided by the
            compression ratio. Logs compress very well in a columnar store, because a column of timestamps,
            severities or service names is mostly repetition. Five to fifteen times is common; the real figure
            depends on how varied your messages are.
        </p>

        <h2>How to measure your own inputs</h2>
        <p>
            For the rate, count a day of lines and divide by 86,400 — <code>wc -l</code> over a day's log file is
            enough. For the size, divide that file's bytes by its lines: <code>wc -c</code> then <code>wc -l</code>.
            If you already run ClickHouse, it will tell you the compression ratio of a table outright:
        </p>
        <pre><code>SELECT
    formatReadableSize(sum(data_uncompressed_bytes)) AS raw,
    formatReadableSize(sum(data_compressed_bytes)) AS on_disk,
    round(sum(data_uncompressed_bytes) / sum(data_compressed_bytes), 1) AS ratio
FROM system.columns
WHERE table = 'otel_logs'</code></pre>

        <h2>Why the two answers grow differently</h2>
        <p>
            A per-GB bill grows in a straight line with every log line you add, which is why teams end up sampling,
            dropping debug logs or shortening retention to keep the invoice down. Disk grows too, but at a tenth of
            the rate and in steps you buy once. That difference is the reason
            <a href="{{ route('home') }}">Bilis</a> exists: OpenTelemetry logs, traces and metrics on your own box,
            with no per-gigabyte line on any bill.
        </p>
    </x-slot:explainer>
</x-tools.page>
