{{-- One figure in a hairline strip: what is counted, the number, and what it
     means. The dashboard's KPI strip and the landing page's proof strip are
     both rows of these. --}}
@props(['label', 'value', 'caption' => null])

<div {{ $attributes->merge(['class' => 'flex flex-col gap-1.5 py-6']) }}>
    <span class="text-xs font-medium text-muted-foreground">{{ $label }}</span>
    <span class="text-title font-semibold tabular-nums">{{ $value }}</span>
    @if ($caption)
        <span class="text-xs text-muted-foreground">{{ $caption }}</span>
    @endif
</div>
