{{-- The page's spine.

     Every section below the hero is numbered in the mono face the log data
     itself is set in. It is the cheapest way to say the page was laid out
     rather than stacked — and it gives the reader a place in the argument.

     Expects $number and $label from the @include. --}}
<p class="flex items-center gap-3 font-mono text-[11px] tracking-[0.18em] text-muted-foreground uppercase">
    <span class="text-foreground/45">{{ $number }}</span>
    <span class="h-px w-6 bg-border"
          aria-hidden="true"></span>
    {{ $label }}
</p>
