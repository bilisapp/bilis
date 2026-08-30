{{-- A live tail, actually tailing.

     The proof that this is a log tool should not be a PNG. These rows are
     real DOM in the real severity tokens and the real mono face; the page
     renders the whole list server-side, and `live-tail.ts` takes over to
     prepend a line every second or two. Without JavaScript it is a static
     stream, which is exactly what a screenshot was — so nothing is lost.

     The lines are illustrative, and the caption under the pane says so. --}}
@php
    /** @var list<array{severity: string, service: string, message: string}> $pool */
    $pool = [
        ['severity' => 'info', 'service' => 'billing', 'message' => 'Invoice finalized for team acme-inc (amount: 4900)'],
        ['severity' => 'debug', 'service' => 'api', 'message' => 'Cache miss for plan:pro — reading from SQLite'],
        ['severity' => 'info', 'service' => 'api', 'message' => 'POST /v1/subscriptions 201 in 91ms'],
        ['severity' => 'info', 'service' => 'worker', 'message' => 'Job "export-csv" restarted after OOM kill'],
        ['severity' => 'fatal', 'service' => 'worker', 'message' => 'Out of memory processing job "export-csv" (2048MB limit)'],
        ['severity' => 'trace', 'service' => 'worker', 'message' => 'Heartbeat ok, queue depth 0'],
        ['severity' => 'info', 'service' => 'api', 'message' => 'GET /v1/logs?window=1h 200 in 142ms'],
        ['severity' => 'debug', 'service' => 'auth', 'message' => 'Session refreshed for user 4821'],
        ['severity' => 'info', 'service' => 'worker', 'message' => 'Processed 1 job in 8ms (queue: default)'],
        ['severity' => 'error', 'service' => 'api', 'message' => 'POST /v1/checkout 500: Undefined array key "currency"'],
        ['severity' => 'info', 'service' => 'ingest', 'message' => 'Accepted 247 log records for project acme-web'],
        ['severity' => 'warn', 'service' => 'ingest', 'message' => 'Batch contained 3 malformed records, skipped'],
        ['severity' => 'info', 'service' => 'auth', 'message' => 'User signed in (method: passkey)'],
        ['severity' => 'debug', 'service' => 'api', 'message' => 'Rate limit bucket for key bilis_7f2a: 118/300 remaining'],
        ['severity' => 'info', 'service' => 'api', 'message' => 'GET /v1/projects 200 in 19ms'],
        ['severity' => 'warn', 'service' => 'ingest', 'message' => 'ClickHouse insert queued, wait_for_async_insert=0'],
        ['severity' => 'info', 'service' => 'billing', 'message' => 'Stripe webhook invoice.paid handled in 240ms'],
        ['severity' => 'error', 'service' => 'billing', 'message' => 'Stripe webhook timed out after 10s, will retry'],
    ];

    $rows = 12;
    $now = now();
@endphp

<div class="overflow-hidden rounded-lg border border-border bg-card dark:border-foreground/15"
     data-live-tail>
    {{-- Chrome enough to place the pane, not so much that it cosplays a browser. --}}
    <div class="flex items-center justify-between gap-4 border-b border-border px-4 py-2.5 dark:border-foreground/10">
        <div class="flex min-w-0 items-center gap-3">
            <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-border px-2 py-0.5 font-mono text-[11px] tracking-[0.14em] uppercase dark:border-foreground/15">
                <span class="size-1.5 rounded-full bg-severity-info"
                      data-live-tail-pulse></span>
                Live
            </span>
            <span class="hidden truncate font-mono text-[11px] text-muted-foreground sm:block">all projects · last hour</span>
        </div>
        <span class="shrink-0 font-mono text-[11px] text-muted-foreground tabular-nums">
            <span data-live-tail-count>1284</span> lines
        </span>
    </div>

    {{-- The list holds a constant number of rows — one arrives at the top,
         the oldest leaves the bottom — so the height never changes and no
         row is ever clipped half-way through. --}}
    <ol class="overflow-hidden"
        data-live-tail-list>
        @foreach (collect($pool)->take($rows) as $index => $line)
            <li class="grid grid-cols-[auto_auto_minmax(0,1fr)] items-baseline gap-x-3 px-4 py-[7px] font-mono text-[12px] leading-4 sm:grid-cols-[6.5rem_5.25rem_5rem_minmax(0,1fr)]">
                {{-- Milliseconds and the severity word are desktop luxuries;
                     on a phone the dot and the message are the whole story. --}}
                <time class="text-muted-foreground tabular-nums">
                    <span data-live-tail-time>{{ $now->copy()->subSeconds($index * 3 + 1)->format('H:i:s') }}</span><span class="hidden sm:inline"
                                                                                                                          data-live-tail-ms>.{{ $now->copy()->subSeconds($index * 3 + 1)->format('v') }}</span>
                </time>
                <span class="flex items-center gap-1.5 text-severity-{{ $line['severity'] }}"
                      data-live-tail-badge>
                    <span class="size-1.5 shrink-0 rounded-full bg-severity-{{ $line['severity'] }}"
                          data-live-tail-dot></span>
                    <span class="hidden sm:inline"
                          data-live-tail-severity>{{ strtoupper($line['severity']) }}</span>
                </span>
                <span class="hidden truncate text-muted-foreground sm:block"
                      data-live-tail-service>{{ $line['service'] }}</span>
                <span class="truncate text-foreground/90"
                      data-live-tail-message>{{ $line['message'] }}</span>
            </li>
        @endforeach
    </ol>
</div>

<script type="application/json"
        data-live-tail-pool
        nonce="{{ $cspNonce ?? '' }}">@json($pool)</script>
