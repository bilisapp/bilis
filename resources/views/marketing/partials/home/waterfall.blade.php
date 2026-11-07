{{-- A trace as the app draws it: services as chart series, the failing span
     in the severity hue, durations in the magnitude ramp on its absolute
     thresholds (50 ms / 500 ms / 5 s — the same as lib/traces.ts). Bars are
     positioned in percent of the root span, so the panel scales with the page. --}}
@php
    $total = 842;
    $spans = [
        ['depth' => 0, 'name' => 'POST /checkout', 'service' => 'storefront-web', 'start' => 0, 'end' => 842, 'tone' => 'bg-chart-1'],
        ['depth' => 1, 'name' => 'checkout.create', 'service' => 'checkout-api', 'start' => 18, 'end' => 790, 'tone' => 'bg-chart-2'],
        ['depth' => 2, 'name' => 'SELECT carts', 'service' => null, 'start' => 30, 'end' => 92, 'tone' => 'bg-chart-2'],
        ['depth' => 2, 'name' => 'payments.charge', 'service' => 'payments', 'start' => 110, 'end' => 760, 'tone' => 'bg-chart-3'],
        ['depth' => 3, 'name' => 'POST /v1/charges', 'service' => null, 'start' => 130, 'end' => 720, 'tone' => 'bg-severity-error', 'failed' => true],
        ['depth' => 2, 'name' => 'emit order.failed', 'service' => null, 'start' => 770, 'end' => 788, 'tone' => 'bg-chart-2'],
    ];
    $magnitude = fn (int $ms): string => match (true) {
        $ms >= 5000 => 'text-magnitude-4',
        $ms >= 500 => 'text-magnitude-3',
        $ms >= 50 => 'text-magnitude-2',
        default => 'text-magnitude-1',
    };
@endphp

<x-ui.panel role="img" aria-label="A trace waterfall: POST /checkout across three services, with the payment provider call failing after 590 milliseconds.">
    <x-slot:header>
        <span class="flex min-w-0 items-baseline gap-2.5">
            <span class="truncate font-mono text-sm font-semibold">POST /checkout</span>
            <span class="hidden text-xs text-muted-foreground sm:inline">3 services · 6 spans · <span class="font-medium text-severity-error">1 failed</span></span>
        </span>
        <span class="font-mono text-xs {{ $magnitude($total) }}">{{ $total }}ms</span>
    </x-slot:header>

    <div class="column-header flex items-center gap-4 border-b border-border px-5 py-2">
        <span class="w-36 shrink-0 sm:w-56">Span</span>
        <span class="flex flex-1 justify-between font-mono tracking-normal normal-case">
            <span>0</span><span class="hidden sm:inline">200ms</span><span class="hidden sm:inline">400ms</span><span class="hidden sm:inline">600ms</span><span>{{ $total }}ms</span>
        </span>
        <span class="w-14 text-right">Took</span>
    </div>

    @foreach ($spans as $span)
        @php($took = $span['end'] - $span['start'])
        <div @class([
            'flex h-9 items-center gap-4 px-5',
            'border-b border-border/60' => ! $loop->last,
            'bg-severity-error/[0.06]' => $span['failed'] ?? false,
        ])>
            <span class="flex w-36 shrink-0 items-center gap-2 truncate font-mono text-xs sm:w-56"
                  style="padding-left: {{ $span['depth'] * 14 }}px">
                <span class="size-2 shrink-0 rounded-[2px] {{ $span['tone'] }}" aria-hidden="true"></span>
                <span @class(['truncate', 'text-severity-error' => $span['failed'] ?? false])>{{ $span['name'] }}</span>
                @if ($span['service'])
                    <span class="hidden truncate text-muted-foreground 2xl:inline">{{ $span['service'] }}</span>
                @endif
            </span>
            <span class="relative h-2.5 flex-1" aria-hidden="true">
                <span class="absolute inset-y-0 rounded-[2px] {{ $span['tone'] }}"
                      style="left: {{ round($span['start'] / $total * 100, 2) }}%; width: {{ max(1, round($took / $total * 100, 2)) }}%"></span>
            </span>
            <span class="w-14 text-right font-mono text-xs {{ $magnitude($took) }}">{{ $took }}ms</span>
        </div>
    @endforeach
</x-ui.panel>
