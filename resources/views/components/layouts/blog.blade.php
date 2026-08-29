{{-- The blog layout.

     Almost the marketing layout, with one thing the other public surfaces do
     not have: feed autodiscovery. The `<link rel="alternate">` lives here
     rather than in the shared head, because the blog is the only surface
     with a feed and a reader pointing an aggregator at `/features` should
     not be handed one. --}}
@props([
    'title' => null,
    'description' => 'Notes on building Bilis — log storage, ClickHouse, OTLP, and the decisions behind a deliberately narrow product.',
])

@php
    $pageTitle = $title
        ? $title.' — '.config('app.name', 'Bilis')
        : config('app.name', 'Bilis').' blog';

    $feedTitle = config('app.name', 'Bilis').' blog';
@endphp

    <!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <x-public.head :title="$pageTitle"
                   :description="$description">
        <link rel="alternate"
              type="application/atom+xml"
              title="{{ $feedTitle }}"
              href="{{ route('blog.feed') }}">

        {{-- The blog is Blade only: the stylesheet, never the Inertia bundle. --}}
        @vite('resources/css/app.css')
    </x-public.head>
</head>
<body class="min-h-dvh bg-background font-sans text-foreground antialiased">
<x-public.header badge="Blog"
                 current="blog" />

<main>
    {{ $slot }}
</main>

<x-public.footer />
</body>
</html>
