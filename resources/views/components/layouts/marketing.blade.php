@props([
    'title' => null,
    'description' => 'Self-hosted log storage and search. OTLP in, ClickHouse underneath, a fast viewer on top.',
    'current' => null,
])

@php
    $pageTitle = $title
        ? $title.' — '.config('app.name', 'Bilis')
        : config('app.name', 'Bilis').' — self-hosted log storage and search';
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
