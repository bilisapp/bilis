{{-- A piece of the product shown on a public page: a raised surface with a
     hairline, an optional header strip, and whatever is inside drawn in the
     app's own components. A panel is a separate object, which is the one
     reason the system allows a card. --}}
@props(['title' => null, 'meta' => null])

<div {{ $attributes->merge(['class' => 'flex flex-col overflow-hidden rounded-lg bg-card ring-1 ring-border']) }}>
    @if ($title || isset($header))
        <div class="flex min-h-11 items-center justify-between gap-4 border-b border-border px-5">
            @isset($header)
                {{ $header }}
            @else
                <span class="eyebrow">{{ $title }}</span>
                @if ($meta)
                    <span class="font-mono text-label text-muted-foreground">{{ $meta }}</span>
                @endif
            @endisset
        </div>
    @endif
    {{ $slot }}
</div>
