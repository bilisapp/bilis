{{-- A public section's opening: numbered eyebrow, headline, and a lead set to
     the right on a wide screen, closed by a hairline. Every section on the
     landing page opens this way, so the page reads as one argument.

     <x-ui.section-header number="01" label="Investigate">
         <x-slot:title>See the whole request.</x-slot:title>
         One trace id joins the waterfall and the stream…
     </x-ui.section-header> --}}
@props(['number' => null, 'label' => null, 'rule' => true])

<div {{ $attributes->class([
    'grid gap-6 lg:grid-cols-12 lg:items-end',
    'border-b border-border pb-8' => $rule,
]) }}>
    <div class="flex flex-col gap-4 lg:col-span-7">
        @if ($label)
            <x-ui.eyebrow :number="$number">{{ $label }}</x-ui.eyebrow>
        @endif
        <h2 class="text-4xl leading-[1.08] font-semibold tracking-display text-balance sm:text-headline">{{ $title }}</h2>
    </div>
    @if ($slot->isNotEmpty())
        <p class="text-sm leading-relaxed text-muted-foreground lg:col-span-4 lg:col-start-9">{{ $slot }}</p>
    @endif
</div>
