{{-- The frame every free tool shares.

     The tool itself first — someone who searched for a converter wants the
     converter above the fold, not a pitch — then the explainer that makes
     the page worth ranking, then the other tools, then one quiet line about
     Bilis. Structured data names the page a free web application so a
     search engine can say so in the result. --}}
@props([
    'route',
    'title',
    'description',
    'heading',
    'intro',
])

@php
    $structuredData = [
        '@context' => 'https://schema.org',
        '@type' => 'WebApplication',
        'name' => $heading,
        'description' => $description,
        'url' => route($route),
        'applicationCategory' => 'DeveloperApplication',
        'operatingSystem' => 'Any',
        'isAccessibleForFree' => true,
        'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'],
        'publisher' => ['@type' => 'Organization', 'name' => config('app.name', 'Bilis'), 'url' => route('home')],
    ];
@endphp

<x-layouts.marketing :title="$title"
                     :description="$description"
                     current="tools">
    <script type="application/ld+json"
            nonce="{{ $cspNonce ?? '' }}">{!! json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>

    <section class="mx-auto max-w-5xl px-6 pt-16 pb-10 sm:pt-20">
        <nav aria-label="Breadcrumb"
             class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
            <a href="{{ route('tools.index') }}"
               class="transition-colors hover:text-foreground">Free tools</a>
        </nav>

        <h1 class="mt-4 max-w-3xl text-3xl leading-tight font-semibold tracking-tight sm:text-4xl">{{ $heading }}</h1>

        <p class="mt-5 max-w-2xl text-sm leading-relaxed text-muted-foreground sm:text-base">{{ $intro }}</p>
    </section>

    <section class="border-y border-border bg-card">
        <div class="mx-auto max-w-5xl px-6 py-10 sm:py-12">
            {{ $slot }}
        </div>
    </section>

    @isset($explainer)
        <section class="mx-auto max-w-5xl px-6 pt-6 pb-16 sm:pb-20">
            <div class="docs-prose max-w-3xl">
                {{ $explainer }}
            </div>
        </section>
    @endisset

    <section class="border-t border-border">
        <div class="mx-auto max-w-5xl px-6 py-16">
            <h2 class="text-xl font-semibold tracking-tight">More free tools</h2>

            <ul class="mt-6 grid gap-4 sm:grid-cols-3">
                @foreach (\App\Services\Tools\ToolCatalog::except($route) as $tool)
                    <li>
                        <a href="{{ route($tool['route']) }}"
                           class="block h-full rounded-xl border border-border p-5 transition-colors hover:bg-accent">
                            <span class="text-sm font-medium">{{ $tool['name'] }}</span>
                            <span class="mt-2 block text-sm leading-relaxed text-muted-foreground">{{ $tool['summary'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>

            <p class="mt-10 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                These tools are made by {{ config('app.name', 'Bilis') }}: self-hosted logs, traces and metrics
                that you and your coding agent read alike, stored on your own box in OpenTelemetry's formats.
                <a href="{{ route('features') }}"
                   class="text-foreground underline underline-offset-4">See what it does</a>
                or
                <a href="{{ route('docs.index') }}"
                   class="text-foreground underline underline-offset-4">read the docs</a>.
            </p>
        </div>
    </section>

    @push('scripts')
        @vite('resources/js/marketing/marketing.ts')
    @endpush
</x-layouts.marketing>
