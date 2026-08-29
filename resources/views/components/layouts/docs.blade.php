@props([
    'title' => null,
    'description' => 'Documentation for Bilis — self-hosted log storage and search.',
    'sections' => [],
    'current' => null,
    'toc' => [],
])

@php
    /** @var array<int, \App\Services\Docs\DocsSection> $sections */
    /** @var \App\Services\Docs\DocsPage|null $current */

    $pageTitle = $title
        ? $title.' — '.config('app.name', 'Bilis').' docs'
        : config('app.name', 'Bilis').' documentation';
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <x-public.head :title="$pageTitle"
                       :description="$description">
            @if ($current)
                {{-- The same page as raw markdown, for anything reading without a browser. --}}
                <link rel="alternate"
                      type="text/markdown"
                      href="{{ $current->markdownUrl() }}">
            @endif

            {{-- Documentation is Blade only: the stylesheet, never the Inertia bundle. --}}
            @vite('resources/css/app.css')
        </x-public.head>
    </head>
    <body class="min-h-dvh bg-background font-sans text-foreground antialiased">
    <x-public.header wide
                     badge="Docs"
                     current="docs" />

        <div class="mx-auto max-w-7xl px-4 sm:px-6">
            <div class="lg:grid lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-10 xl:grid-cols-[15rem_minmax(0,1fr)_13rem]">
                {{-- Mobile: the same nav, folded into a disclosure so the page needs no JavaScript. --}}
                <details class="mt-4 rounded-xl border border-border bg-card p-4 lg:hidden">
                    <summary class="cursor-pointer text-sm font-medium">Documentation</summary>
                    <div class="mt-4">
                        <x-docs.nav :sections="$sections" :current="$current" />
                    </div>
                </details>

                <aside class="scrollbar-stream sticky top-16 hidden h-[calc(100dvh-4rem)] overflow-y-auto py-10 pr-2 lg:block">
                    <x-docs.nav :sections="$sections" :current="$current" />
                </aside>

                <main class="min-w-0 py-10">
                    {{ $slot }}
                </main>

                @if ($toc !== [])
                    <aside class="scrollbar-stream sticky top-16 hidden h-[calc(100dvh-4rem)] overflow-y-auto py-10 xl:block">
                        <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">On this page</p>
                        <ul class="mt-3 space-y-2 text-sm">
                            @foreach ($toc as $entry)
                                <li @class(['pl-3' => $entry['level'] === 3])>
                                    <a href="#{{ $entry['id'] }}"
                                       class="block text-muted-foreground transition-colors hover:text-foreground">
                                        {{ $entry['title'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </aside>
                @endif
            </div>
        </div>

    <x-public.footer wide
                     class="mt-8" />
    </body>
</html>
