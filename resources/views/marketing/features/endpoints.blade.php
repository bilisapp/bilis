{{-- The three ways in, as one ledger.

     A table would need horizontal scrolling on a phone for the sake of three
     columns; the same rows stack instead, on the page's hairlines, with the
     method and path in the mono face the log data itself is set in. --}}
<ul class="mt-8 divide-y divide-border border-t border-b border-border">
    @foreach ([
        [
            'method' => 'POST',
            'path' => '/api/v1/logs',
            'status' => '200',
            'payload' => 'OTLP ExportLogsServiceRequest, JSON or protobuf',
            'note' => 'What every OTel SDK and the Collector already speak.',
        ],
        [
            'method' => 'POST',
            'path' => '/api/v1/traces',
            'status' => '200',
            'payload' => 'OTLP ExportTraceServiceRequest, JSON or protobuf',
            'note' => 'Spans from the same SDKs, on the same key, under the same contract.',
        ],
        [
            'method' => 'POST',
            'path' => '/api/v1/ingest',
            'status' => '202',
            'payload' => 'Plain JSON: one object or a list of them',
            'note' => 'For anything with no OTel exporter. Only the message is required.',
        ],
        [
            'method' => 'POST',
            'path' => '/api/{id}/envelope',
            'status' => '204',
            'payload' => 'A Sentry SDK envelope',
            'note' => 'Configured with a DSN built from a key\'s public half.',
        ],
    ] as $endpoint)
        <li class="grid gap-2 py-4 sm:grid-cols-[minmax(0,15rem)_1fr] sm:gap-6">
            <div class="min-w-0">
                <p class="font-mono text-xs break-all">
                    <span class="text-muted-foreground">{{ $endpoint['method'] }}</span>
                    {{ $endpoint['path'] }}
                </p>
                <p class="mt-1 font-mono text-[11px] tracking-[0.14em] text-muted-foreground uppercase">
                    Success <span class="text-severity-debug">{{ $endpoint['status'] }}</span>
                </p>
            </div>

            <div class="min-w-0">
                <p class="text-sm">{{ $endpoint['payload'] }}</p>
                <p class="mt-1 text-sm leading-relaxed text-muted-foreground">{{ $endpoint['note'] }}</p>
            </div>
        </li>
    @endforeach
</ul>
