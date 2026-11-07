{{-- A quiet pill. Neutral by default; `alarm` is the one state that spends
     the severity hue (regressed, failed) — the same rule the app follows. --}}
@props(['variant' => 'neutral'])

<span {{ $attributes->class([
    'inline-flex h-5 shrink-0 items-center gap-1 rounded-full px-2 text-label font-medium',
    'text-foreground/80 ring-1 ring-border ring-inset' => $variant === 'neutral',
    'text-muted-foreground ring-1 ring-input ring-inset' => $variant === 'muted',
    'bg-severity-error/10 text-severity-error' => $variant === 'alarm',
]) }}>{{ $slot }}</span>
