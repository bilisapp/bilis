@props([
    'title' => null,
    'description' => 'Self-hosted logs, traces and metrics that you and your coding agent read alike — on your own box, in OpenTelemetry formats, with no per-GB bill.',
    'current' => null,
])

@php
    $pageTitle = $title
        ? $title.' — '.config('app.name', 'Bilis')
        : config('app.name', 'Bilis').' — self-hosted logs, traces and metrics';
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <x-public.head :title="$pageTitle"
                       :description="$description">
            {{-- Marketing pages are Blade only: the stylesheet, never the Inertia bundle. --}}
            @vite('resources/css/app.css')
        </x-public.head>
    </head>
    <body class="min-h-dvh bg-background font-sans text-foreground antialiased">
    <x-public.header :current="$current" />

        <main>
            {{ $slot }}
        </main>

    <x-public.footer />

        @stack('scripts')
    </body>
</html>
