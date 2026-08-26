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
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ? $title.' — '.config('app.name', 'Bilis').' docs' : config('app.name', 'Bilis').' documentation' }}</title>
        <meta name="description" content="{{ $description }}">

        {{-- Public pages follow the operating system only; the appearance toggle lives in the app. --}}
        <script>
            if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                document.documentElement.classList.add('dark');
            }
        </script>

        {{-- Paint the page ground before the stylesheet lands, so there is no flash. --}}
        <style>
            html {
                background-color: hsl(44 33% 93%);
            }

            html.dark {
                background-color: hsl(28 22% 9%);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48 64x64">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

        <meta name="theme-color" content="#f0ebdd" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#1c1611" media="(prefers-color-scheme: dark)">

        @fonts

        {{-- Documentation is Blade only: the stylesheet, never the Inertia bundle. --}}
        @vite('resources/css/app.css')
    </head>
    <body class="min-h-dvh bg-background font-sans text-foreground antialiased">
        <header class="sticky top-0 z-30 border-b border-border bg-background/90 backdrop-blur">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
                <div class="flex min-w-0 items-center gap-2.5">
                    <a href="{{ route('home') }}" class="flex shrink-0 items-center gap-2.5">
                        <img src="/logo-mark.svg" alt="" class="hidden h-6 w-auto sm:block" width="20790" height="4080">
                        <img src="/logo-icon.svg" alt="" class="size-6 sm:hidden" width="17586" height="17586">
                        <span class="text-base font-semibold tracking-tight">{{ config('app.name', 'Bilis') }}</span>
                    </a>
                    <span class="rounded-md border border-border px-1.5 py-0.5 text-xs font-medium text-muted-foreground">Docs</span>
                </div>

                <nav class="flex shrink-0 items-center gap-1 text-sm font-medium whitespace-nowrap">
                    <a href="{{ config('bilis.github_url') }}"
                       class="hidden items-center gap-1.5 rounded-md px-3 py-2 transition-colors hover:bg-accent hover:text-accent-foreground sm:flex"
                       target="_blank" rel="noopener noreferrer">
                        <x-icons.github class="size-4" />
                        Source
                    </a>

                    @auth
                        @if ($team = auth()->user()->currentTeam)
                            <a href="{{ route('dashboard', $team) }}"
                               class="rounded-md bg-primary px-3 py-2 text-primary-foreground transition-colors hover:bg-primary/90 sm:px-4">
                                Dashboard
                            </a>
                        @endif
                    @else
                        <a href="{{ route('login') }}"
                           class="rounded-md px-3 py-2 transition-colors hover:bg-accent hover:text-accent-foreground sm:px-4">
                            Log in
                        </a>
                    @endauth
                </nav>
            </div>
        </header>

        <div class="mx-auto max-w-7xl px-4 sm:px-6">
            <div class="lg:grid lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-10 xl:grid-cols-[15rem_minmax(0,1fr)_13rem]">
                {{-- Mobile: the same nav, folded into a disclosure so the page needs no JavaScript. --}}
                <details class="mt-4 rounded-xl border border-border bg-card p-4 lg:hidden">
                    <summary class="cursor-pointer text-sm font-medium">Documentation</summary>
                    <div class="mt-4">
                        <x-docs.nav :sections="$sections" :current="$current" />
                    </div>
                </details>

                <aside class="scrollbar-stream sticky top-14 hidden h-[calc(100dvh-3.5rem)] overflow-y-auto py-10 pr-2 lg:block">
                    <x-docs.nav :sections="$sections" :current="$current" />
                </aside>

                <main class="min-w-0 py-10">
                    {{ $slot }}
                </main>

                @if ($toc !== [])
                    <aside class="scrollbar-stream sticky top-14 hidden h-[calc(100dvh-3.5rem)] overflow-y-auto py-10 xl:block">
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

        <footer class="mt-8 border-t border-border">
            <div class="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-8 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div class="flex items-center gap-2.5">
                    <img src="/logo-mark.svg" alt="" class="h-5 w-auto" width="20790" height="4080">
                    <span>{{ config('app.name', 'Bilis') }} — self-hosted log storage and search.</span>
                </div>
                <div class="flex items-center gap-4">
                    <a href="{{ route('home') }}" class="transition-colors hover:text-foreground">Home</a>
                    <a href="{{ config('bilis.github_url') }}"
                       class="flex items-center gap-1.5 transition-colors hover:text-foreground"
                       target="_blank" rel="noopener noreferrer">
                        <x-icons.github class="size-4" />
                        Source on GitHub
                    </a>
                    <a href="{{ route('terms') }}" class="transition-colors hover:text-foreground">Terms</a>
                    <a href="{{ route('privacy') }}" class="transition-colors hover:text-foreground">Privacy</a>
                </div>
            </div>
        </footer>
    </body>
</html>
