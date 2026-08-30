<x-layouts.docs
    :title="$page->title"
    :description="$page->description ?? 'Documentation for Bilis — self-hosted logs and traces.'"
    :sections="$sections"
    :current="$page"
    :toc="$rendered->tableOfContents"
>
    <article class="max-w-3xl">
        <header>
            <div class="flex items-start justify-between gap-4">
                <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {{ collect($sections)->firstWhere('slug', $page->section)?->title }}
                </p>

                <x-docs.copy-markdown :url="$page->markdownUrl()" />
            </div>

            <h1 class="mt-2 text-3xl font-semibold tracking-tight">{{ $page->title }}</h1>

            @if ($page->description)
                <p class="mt-3 text-base leading-relaxed text-muted-foreground">{{ $page->description }}</p>
            @endif
        </header>

        @if ($needsApiKey)
            <x-docs.api-key-panel :projects="$projects" />
            <x-docs.copy-prompt :page="$page" />
        @endif

        <div class="docs-prose mt-10">
            {!! $rendered->html !!}
        </div>

        @if ($neighbours['previous'] || $neighbours['next'])
            <nav class="mt-16 flex flex-col gap-3 border-t border-border pt-8 sm:flex-row sm:justify-between">
                @if ($previous = $neighbours['previous'])
                    <a href="{{ $previous->url() }}"
                       class="rounded-xl border border-border bg-card px-4 py-3 text-sm transition-colors hover:bg-accent hover:text-accent-foreground">
                        <span class="block text-xs text-muted-foreground">Previous</span>
                        <span class="font-medium">{{ $previous->title }}</span>
                    </a>
                @endif

                @if ($next = $neighbours['next'])
                    <a href="{{ $next->url() }}"
                       class="rounded-xl border border-border bg-card px-4 py-3 text-sm transition-colors hover:bg-accent hover:text-accent-foreground sm:ml-auto sm:text-right">
                        <span class="block text-xs text-muted-foreground">Next</span>
                        <span class="font-medium">{{ $next->title }}</span>
                    </a>
                @endif
            </nav>
        @endif
    </article>
</x-layouts.docs>
