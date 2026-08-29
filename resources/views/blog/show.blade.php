<x-layouts.blog :title="$post->title"
                :description="$post->description">
    <article class="mx-auto max-w-3xl px-6 py-14 sm:py-16">
        <header>
            <p class="flex flex-wrap items-center gap-x-3 gap-y-1 font-mono text-[11px] tracking-[0.18em] text-muted-foreground uppercase">
                <a href="{{ route('blog.index') }}"
                   class="transition-colors hover:text-foreground">Blog</a>
                <span class="h-px w-6 bg-border"
                      aria-hidden="true"></span>
                <time datetime="{{ $post->date->toDateString() }}">{{ $post->date->format('j M Y') }}</time>
                @if ($post->author)
                    <span aria-hidden="true">·</span>
                    <span>{{ $post->author }}</span>
                @endif
            </p>

            <h1 class="mt-4 text-3xl font-semibold tracking-tight text-balance sm:text-4xl">{{ $post->title }}</h1>

            @if ($post->description)
                <p class="mt-4 text-base leading-relaxed text-muted-foreground">{{ $post->description }}</p>
            @endif
        </header>

        @if ($toc !== [])
            {{-- Only long posts get one: a contents list for three headings
                 costs more attention than the scrolling it saves, so the
                 controller withholds it below four. --}}
            <nav class="mt-10 rounded-xl border border-border bg-card px-5 py-4"
                 aria-label="On this page">
                <p class="font-mono text-[11px] tracking-[0.18em] text-muted-foreground uppercase">On this page</p>

                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($toc as $entry)
                        <li @class(['pl-4' => $entry['level'] === 3])>
                            <a href="#{{ $entry['id'] }}"
                               class="text-muted-foreground transition-colors hover:text-foreground">
                                {{ $entry['title'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        @endif

        <div class="docs-prose mt-10">
            {!! $rendered->html !!}
        </div>

        @if ($neighbours['newer'] || $neighbours['older'])
            {{-- Named by age rather than by direction: on a list that runs
                 newest first, "previous" means the opposite thing depending
                 on whether the reader is thinking about the page or the
                 calendar. --}}
            <nav class="mt-16 grid gap-3 border-t border-border pt-8 sm:grid-cols-2"
                 aria-label="More posts">
                @if ($older = $neighbours['older'])
                    <a href="{{ $older->url() }}"
                       class="rounded-xl border border-border bg-card px-4 py-3 transition-colors hover:bg-accent hover:text-accent-foreground">
                        <span class="block font-mono text-[11px] tracking-[0.18em] text-muted-foreground uppercase">Older</span>
                        <span class="mt-1 block text-sm font-medium">{{ $older->title }}</span>
                    </a>
                @endif

                @if ($newer = $neighbours['newer'])
                    <a href="{{ $newer->url() }}"
                       class="rounded-xl border border-border bg-card px-4 py-3 transition-colors hover:bg-accent hover:text-accent-foreground sm:col-start-2 sm:text-right">
                        <span class="block font-mono text-[11px] tracking-[0.18em] text-muted-foreground uppercase">Newer</span>
                        <span class="mt-1 block text-sm font-medium">{{ $newer->title }}</span>
                    </a>
                @endif
            </nav>
        @endif

        <p class="mt-8 flex flex-wrap items-center gap-x-4 gap-y-2 font-mono text-[11px] tracking-[0.18em] text-muted-foreground uppercase">
            <a href="{{ route('blog.index') }}"
               class="transition-colors hover:text-foreground">All posts</a>
            <span class="h-px w-6 bg-border"
                  aria-hidden="true"></span>
            <a href="{{ route('blog.feed') }}"
               class="transition-colors hover:text-foreground">Atom feed</a>
        </p>
    </article>
</x-layouts.blog>
