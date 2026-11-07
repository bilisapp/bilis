{{-- The small uppercase line above a title: a scope, a trail, or a numbered
     section. Same `eyebrow` utility the app's PageHeader uses.

     <x-ui.eyebrow number="01">Investigate</x-ui.eyebrow> --}}
@props(['number' => null])

<p {{ $attributes->merge(['class' => 'eyebrow flex flex-wrap items-center gap-2']) }}>
    @if ($number)
        <span class="font-mono text-foreground">{{ $number }}</span>
    @endif
    {{ $slot }}
</p>
