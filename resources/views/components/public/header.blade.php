{{-- The one public header.

     The landing page, the docs, the blog and the styleguide all wear it, so
     the only thing that varies is the container width (the docs and the
     styleguide need the wide grid), an optional badge naming the surface,
     and which nav item is current. A visitor moving between them should not
     be able to tell that one is Blade and another is Inertia.

     `mark-live` lights the three tail stripes in sequence. It only reaches
     them because the mark is inline SVG here rather than an <img>, and it
     stops under prefers-reduced-motion. --}}
@props([
    'wide' => false,
    'badge' => null,
    'current' => null,
])

@php
    $links = [
        'features' => ['label' => 'Features', 'href' => route('features')],
        'docs' => ['label' => 'Docs', 'href' => route('docs.index')],
        'blog' => ['label' => 'Blog', 'href' => route('blog.index')],
    ];
@endphp

<header class="sticky top-0 z-30 border-b border-border bg-background/90 backdrop-blur">
    <div @class([
        'mx-auto flex h-16 items-center justify-between gap-4 px-4 sm:px-6',
        'max-w-7xl' => $wide,
        'max-w-5xl' => ! $wide,
    ])>
        <div class="flex min-w-0 items-center gap-2.5">
            <a href="{{ route('home') }}"
               class="mark-live flex shrink-0 items-center gap-2.5">
                {{-- The wide mark where there is room to run; the square mark where there is not. --}}
                <x-marketing.logo-mark class="hidden h-7 w-auto sm:block" />
                <x-marketing.logo-icon class="size-7 sm:hidden" />
                <span class="text-lg font-semibold tracking-tight">{{ config('app.name', 'Bilis') }}</span>
            </a>

            @if ($badge)
                <span class="hidden rounded-md border border-border px-1.5 py-0.5 font-mono text-[11px] tracking-[0.14em] text-muted-foreground uppercase sm:inline">{{ $badge }}</span>
            @endif
        </div>

        <nav class="flex shrink-0 items-center gap-1 text-sm font-medium whitespace-nowrap">
            @foreach ($links as $key => $link)
                <a href="{{ $link['href'] }}"
                   @if ($current === $key) aria-current="page" @endif
                    @class([
                        'hidden rounded-md px-3 py-2 transition-colors sm:block',
                        'text-foreground' => $current === $key,
                        'text-muted-foreground hover:bg-accent hover:text-accent-foreground' => $current !== $key,
                    ])>
                    {{ $link['label'] }}
                </a>
            @endforeach

            <a href="{{ config('bilis.github_url') }}"
               class="hidden items-center gap-1.5 rounded-md px-3 py-2 text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground lg:flex"
               target="_blank"
               rel="noopener noreferrer">
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

    {{-- Below `sm:` the section links do not fit beside the buttons, so they
         get their own scrolling row rather than being hidden behind a menu. --}}
    <div class="scrollbar-stream flex items-center gap-1 overflow-x-auto border-t border-border px-4 py-1.5 text-sm font-medium sm:hidden">
        @foreach ($links as $key => $link)
            <a href="{{ $link['href'] }}"
               @if ($current === $key) aria-current="page" @endif
                @class([
                    'shrink-0 rounded-md px-2.5 py-1 transition-colors',
                    'text-foreground' => $current === $key,
                    'text-muted-foreground' => $current !== $key,
                ])>
                {{ $link['label'] }}
            </a>
        @endforeach
    </div>
</header>
