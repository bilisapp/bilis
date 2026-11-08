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
        ['severity' => 'warn', 'service' => 'ingest', 'message' => 'ClickHouse insert slow, flushed after 840 ms'],
        ['severity' => 'info', 'service' => 'billing', 'message' => 'Stripe webhook invoice.paid handled in 240ms'],
        ['severity' => 'error', 'service' => 'billing', 'message' => 'Stripe webhook timed out after 10s, will retry'],
    ];

    $rows = 12;
    $now = now();
@endphp

<div class="flex flex-col overflow-hidden rounded-lg bg-card ring-1 ring-border"
     data-live-tail>
    {{-- Chrome enough to place the pane, not so much that it cosplays a browser. --}}
    <div class="flex min-h-11 items-center justify-between gap-4 border-b border-border px-5">
        <div class="flex min-w-0 items-center gap-3">
            <span class="inline-flex shrink-0 items-center gap-1.5 text-xs font-medium text-severity-info">
                <span class="size-1.5 rounded-full bg-severity-info"
                      data-live-tail-pulse></span>
                Tailing
            </span>
            <span class="hidden truncate font-mono text-xs text-muted-foreground sm:block">all projects · last hour</span>
        </div>
        <span class="shrink-0 font-mono text-xs text-muted-foreground tabular-nums">
            <span data-live-tail-count>1284</span> lines
        </span>
    </div>

    <div class="column-header hidden grid-cols-[6.5rem_3.25rem_5.5rem_minmax(0,1fr)] gap-x-4 border-b border-border px-5 py-2 sm:grid">
        <span>Time</span><span>Level</span><span>Service</span><span>Message</span>
    </div>

    {{-- The list holds a constant number of rows — one arrives at the top,
         the oldest leaves the bottom — so the height never changes and no
         row is ever clipped half-way through. Each row wears the stream's
         severity stripe down its left edge, as the log viewer does. --}}
    <ol class="overflow-hidden"
        data-live-tail-list>
        @foreach (collect($pool)->take($rows) as $index => $line)
            <li class="relative grid grid-cols-[auto_minmax(0,1fr)] items-baseline gap-x-4 border-b border-border/60 px-5 py-[7px] font-mono text-xs leading-4 last:border-b-0 sm:grid-cols-[6.5rem_3.25rem_5.5rem_minmax(0,1fr)]">
                <span class="absolute inset-y-0 left-0 w-0.5 bg-severity-{{ $line['severity'] }}"
                      aria-hidden="true"
                      data-live-tail-dot></span>
                <time class="text-muted-foreground tabular-nums">
                    <span data-live-tail-time>{{ $now->copy()->subSeconds($index * 3 + 1)->format('H:i:s') }}</span><span class="hidden sm:inline"
                                                                                                                          data-live-tail-ms>.{{ $now->copy()->subSeconds($index * 3 + 1)->format('v') }}</span>
                </time>
                <span class="hidden text-label font-semibold tracking-[0.04em] text-severity-{{ $line['severity'] }} sm:inline"
                      data-live-tail-badge><span data-live-tail-severity>{{ strtoupper($line['severity']) }}</span></span>
                <span class="hidden truncate text-muted-foreground sm:block"
                      data-live-tail-service>{{ $line['service'] }}</span>
                <span class="truncate text-foreground/85"
                      data-live-tail-message>{{ $line['message'] }}</span>
            </li>
        @endforeach
    </ol>
</div>

<script type="application/json"
        data-live-tail-pool
        nonce="{{ $cspNonce ?? '' }}">@json($pool)</script>
