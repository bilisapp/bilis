{{-- The product, not a picture of it: the Issues page drawn in the app's own
     language — the rail, the page header, the column header, rows with a
     severity edge and bar trends. The data is illustrative; every pattern is
     the one a signed-in visitor will meet.

     Sparkline bars are plain divs on a shared scale (8 events = full height),
     so the column compares honestly, exactly as BarSparkline does in the app. --}}
@php
    $issues = [
        ['type' => 'PaymentDeclinedError', 'message' => 'Card ending 4242 was declined by the issuer', 'origin' => 'checkout.js:11 chargeCard()', 'events' => 8, 'seen' => '8m ago', 'regressed' => true, 'series' => [0, 2, 1, 0, 0, 0, 0, 0, 0, 0, 3, 8]],
        ['type' => 'TypeError', 'message' => "Cannot read properties of undefined (reading 'grand')", 'origin' => 'cart.js:8 cartSummary()', 'events' => 7, 'seen' => '12m ago', 'regressed' => false, 'series' => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 2, 5]],
        ['type' => 'Error', 'message' => 'Failed to load product recommendations', 'origin' => 'recommendations.js:11', 'events' => 4, 'seen' => '12m ago', 'regressed' => false, 'series' => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 3]],
        ['type' => 'RangeError', 'message' => 'Invalid array length', 'origin' => 'recommendations.js:24 estimateShipping()', 'events' => 2, 'seen' => '13m ago', 'regressed' => false, 'series' => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 2]],
    ];
@endphp

<div class="flex overflow-hidden rounded-xl bg-sidebar shadow-[0_40px_80px_-20px_rgb(0_0_0/0.35)] ring-1 ring-border dark:shadow-[0_40px_80px_-20px_rgb(0_0_0/0.6)]"
     data-product-frame
     aria-label="The Bilis issues page"
     role="img">
    {{-- The rail, as the app draws it. --}}
    <div class="hidden w-48 shrink-0 flex-col gap-4 p-3 text-xs text-sidebar-foreground md:flex" aria-hidden="true">
        <x-marketing.logo-mark class="ml-1.5 h-5 w-auto self-start" />
        <span class="px-2 py-1.5">Dashboard</span>
        <div class="flex flex-col gap-0.5">
            <span class="px-2 pb-1 text-[10px] font-semibold tracking-[0.12em] uppercase opacity-80">Explore</span>
            <span class="px-2 py-1.5">Logs</span>
            <span class="px-2 py-1.5">Traces</span>
            <span class="px-2 py-1.5">Metrics</span>
        </div>
        <div class="flex flex-col gap-0.5">
            <span class="px-2 pb-1 text-[10px] font-semibold tracking-[0.12em] uppercase opacity-80">Triage</span>
            <span class="flex items-center justify-between rounded-md bg-card px-2 py-1.5 font-medium text-foreground ring-1 ring-border">
                Issues
                <span class="flex items-center gap-1 rounded bg-muted px-1.5 font-mono text-[10px]"><span class="size-1.5 rounded-full bg-severity-error"></span>5</span>
            </span>
            <span class="px-2 py-1.5">Autofix</span>
        </div>
    </div>

    {{-- The work surface. --}}
    <div class="m-2 flex min-w-0 flex-1 flex-col overflow-hidden rounded-[10px] bg-card ring-1 ring-border md:ml-0">
        <div class="flex flex-wrap items-end justify-between gap-4 px-5 pt-6 pb-4 sm:px-7">
            <div class="flex flex-col gap-1.5">
                <span class="eyebrow">storefront-web / production</span>
                <span class="text-2xl leading-7 font-semibold tracking-tight">Issues</span>
                <span class="text-xs text-muted-foreground"><span class="font-medium text-foreground">5 unresolved</span> · last 7 days · <span class="font-medium text-severity-error">1 regressed</span></span>
            </div>
            <div class="hidden gap-0.5 rounded-lg bg-muted p-[3px] text-label font-medium text-muted-foreground sm:flex" aria-hidden="true">
                <span class="rounded-md bg-card px-2.5 py-1 text-foreground ring-1 ring-border">Unresolved</span>
                <span class="px-2.5 py-1">Resolved</span>
                <span class="px-2.5 py-1">All</span>
            </div>
        </div>

        <div class="column-header flex items-center gap-5 border-y border-border px-5 py-2 sm:px-7">
            <span class="flex-1">Issue</span>
            <span class="hidden w-28 sm:block">Last 7 days</span>
            <span class="w-12 text-right">Events</span>
            <span class="hidden w-20 text-right whitespace-nowrap sm:block">Last seen</span>
        </div>

        @foreach ($issues as $issue)
            <div @class([
                'relative flex items-center gap-5 px-5 py-3 sm:px-7',
                'border-b border-border/70' => ! $loop->last,
            ])>
                @if ($issue['regressed'])
                    <span class="absolute inset-y-2.5 left-0 w-[3px] rounded-r-sm bg-severity-error" aria-hidden="true"></span>
                @endif
                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <div class="flex items-center gap-2">
                        <span class="truncate font-mono text-xs font-semibold">{{ $issue['type'] }}</span>
                        <x-ui.badge :variant="$issue['regressed'] ? 'alarm' : 'neutral'" class="h-4 px-1.5 text-[10px]">{{ $issue['regressed'] ? 'Regressed' : 'New' }}</x-ui.badge>
                    </div>
                    <span class="truncate text-xs text-foreground/80">{{ $issue['message'] }}</span>
                    <span class="truncate font-mono text-[11px] text-muted-foreground">{{ $issue['origin'] }} · storefront-web</span>
                </div>
                <div class="hidden h-6 w-28 items-end gap-[3px] border-b border-border sm:flex" aria-hidden="true">
                    @foreach ($issue['series'] as $value)
                        <span class="flex-1 rounded-t-[1px] {{ $value > 0 ? 'bg-severity-error' : '' }}"
                              style="height: {{ $value > 0 ? max(12, round($value / 8 * 100)) : 0 }}%"></span>
                    @endforeach
                </div>
                <span class="w-12 text-right font-mono text-xs font-medium tabular-nums">{{ $issue['events'] }}</span>
                <span class="hidden w-20 text-right text-xs sm:block">{{ $issue['seen'] }}</span>
            </div>
        @endforeach
    </div>
</div>
