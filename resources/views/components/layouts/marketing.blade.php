@props([
    'title' => null,
    'description' => 'Self-hosted log storage and search. OTLP in, ClickHouse underneath, a fast viewer on top.',
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ? $title.' — '.config('app.name', 'Bilis') : config('app.name', 'Bilis').' — self-hosted log storage and search' }}</title>
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

        {{-- Marketing pages are Blade only: the stylesheet, never the Inertia bundle. --}}
        @vite('resources/css/app.css')
    </head>
    <body class="min-h-dvh bg-background font-sans text-foreground antialiased">
        <header class="border-b border-border">
            <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
                <a href="{{ route('home') }}" class="flex shrink-0 items-center gap-2.5">
                    {{-- The wide mark where there is room to run; the square mark where there is not. --}}
                    <img src="/logo-mark.svg" alt="" class="hidden h-7 w-auto sm:block" width="20790" height="4080">
                    <img src="/logo-icon.svg" alt="" class="size-7 sm:hidden" width="17586" height="17586">
                    <span class="text-lg font-semibold tracking-tight">{{ config('app.name', 'Bilis') }}</span>
                </a>

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
                        <a href="{{ route('register') }}"
                           class="rounded-md bg-primary px-3 py-2 text-primary-foreground transition-colors hover:bg-primary/90 sm:px-4">
                            Get started
                        </a>
                    @endauth
                </nav>
            </div>
        </header>

        <main>
            {{ $slot }}
        </main>

        <footer class="border-t border-border">
            <div class="mx-auto flex max-w-5xl flex-col gap-4 px-6 py-8 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-2.5">
                    <img src="/logo-mark.svg" alt="" class="h-5 w-auto" width="20790" height="4080">
                    <span>{{ config('app.name', 'Bilis') }} — self-hosted log storage and search.</span>
                </div>
                <div class="flex items-center gap-4">
                    <a href="{{ config('bilis.github_url') }}"
                       class="flex items-center gap-1.5 transition-colors hover:text-foreground"
                       target="_blank" rel="noopener noreferrer">
                        <x-icons.github class="size-4" />
                        Source on GitHub
                    </a>
                    <span>Logs only. That is the whole product.</span>
                </div>
            </div>
        </footer>
    </body>
</html>
