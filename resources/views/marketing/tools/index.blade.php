@php
    /** @var list<array{route: string, name: string, summary: string}> $tools */
@endphp

<x-layouts.marketing title="Free tools for logs and traces"
                     description="Free tools for working with logs and traces: a log cost calculator, log levels mapped across languages, a traceparent decoder and a nanosecond timestamp converter."
                     current="tools">
    <section class="mx-auto max-w-5xl px-6 pt-16 pb-12 sm:pt-20">
        <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Free tools</p>

        <h1 class="mt-4 max-w-3xl text-3xl leading-tight font-semibold tracking-tight sm:text-4xl">
            Small tools for the questions logs and traces raise
        </h1>

        <p class="mt-5 max-w-2xl text-sm leading-relaxed text-muted-foreground sm:text-base">
            No sign-up, nothing to install, and every result is a link you can paste into a ticket. They work
            without JavaScript, too.
        </p>
    </section>

    <section class="border-y border-border bg-card">
        <ul class="mx-auto grid max-w-5xl gap-6 px-6 py-16 sm:grid-cols-2 sm:py-20">
            @foreach ($tools as $tool)
                <li>
                    <a href="{{ route($tool['route']) }}"
                       class="block h-full rounded-xl border border-border bg-background p-6 transition-colors hover:bg-accent">
                        <h2 class="text-lg font-semibold tracking-tight">{{ $tool['name'] }}</h2>
                        <p class="mt-2 text-sm leading-relaxed text-muted-foreground">{{ $tool['summary'] }}</p>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="mx-auto max-w-5xl px-6 py-16">
        <p class="max-w-2xl text-sm leading-relaxed text-muted-foreground">
            Made by {{ config('app.name', 'Bilis') }} — self-hosted logs, traces and metrics that you and your coding
            agent read alike. Self-hosting is free and first-class; a hosted Free plan exists too.
            <a href="{{ route('features') }}"
               class="text-foreground underline underline-offset-4">See what it does</a>.
        </p>
    </section>
</x-layouts.marketing>
