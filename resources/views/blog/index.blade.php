<x-layouts.blog
    title="Blog"
    description="Notes on building Bilis: log storage, ClickHouse, OTLP, and the decisions behind a deliberately narrow product."
>
    {{-- The masthead sits on the card ground, so the archive below it reads
         as the page proper rather than as a second block on the same sheet. --}}
    <section class="border-b border-border bg-card">
        <div class="mx-auto max-w-5xl px-6 py-14 sm:py-16">
            <p class="flex items-center gap-3 font-mono text-[11px] tracking-[0.18em] text-muted-foreground uppercase">
                <span class="text-foreground/45">{{ str_pad((string) count($posts), 2, '0', STR_PAD_LEFT) }}</span>
                <span class="h-px w-6 bg-border"
                      aria-hidden="true"></span>
                {{ count($posts) === 1 ? 'Post' : 'Posts' }}
            </p>

            <h1 class="mt-4 max-w-2xl text-3xl font-semibold tracking-tight sm:text-4xl">
                Notes on building Bilis
            </h1>

            <p class="mt-4 max-w-2xl text-sm leading-relaxed text-muted-foreground sm:text-base">
                What we chose, what we left out, and why. Mostly the parts that are hard to see from
                the outside: the shape of the ClickHouse table, the contract at the edge of ingest,
                and the features that are missing on purpose.
            </p>

            <a href="{{ route('blog.feed') }}"
               class="mt-6 inline-flex items-center gap-2 font-mono text-[11px] tracking-[0.18em] text-muted-foreground uppercase transition-colors hover:text-foreground">
                <svg viewBox="0 0 16 16"
                     fill="currentColor"
                     class="size-3.5"
                     aria-hidden="true">
                    <circle cx="3"
                            cy="13"
                            r="2" />
                    <path d="M1 8.2a.9.9 0 0 1 1-.9A6.7 6.7 0 0 1 8.7 14a.9.9 0 0 1-1.8.1A4.9 4.9 0 0 0 2 9.1a.9.9 0 0 1-1-.9Z" />
                    <path d="M1 3.1a.9.9 0 0 1 1-.9A11.8 11.8 0 0 1 13.8 14a.9.9 0 0 1-1.8.1A10 10 0 0 0 2 4a.9.9 0 0 1-1-.9Z" />
                </svg>
                Atom feed
            </a>
        </div>
    </section>

    <div class="mx-auto max-w-5xl px-6 py-14 sm:py-16">
        @if ($posts === [])
            <p class="text-sm text-muted-foreground">Nothing published yet.</p>
        @else
            {{-- An index, not a stack of cards: one hairline per post, the
                 date in the mono face on its own column so the archive has a
                 spine to read down. --}}
            <ul class="divide-y divide-border border-t border-b border-border">
                @foreach ($posts as $post)
                    <li class="group relative sm:grid sm:grid-cols-[8rem_minmax(0,1fr)] sm:gap-8">
                        <p class="pt-6 font-mono text-[11px] tracking-[0.18em] text-muted-foreground uppercase sm:pb-6">
                            <time datetime="{{ $post->date->toDateString() }}">{{ $post->date->format('j M Y') }}</time>
                        </p>

                        <div class="pt-2 pb-6 sm:pt-6">
                            <h2 class="text-lg font-semibold tracking-tight sm:text-xl">
                                <a href="{{ $post->url() }}"
                                   class="transition-colors after:absolute after:inset-0 group-hover:text-muted-foreground">
                                    {{ $post->title }}
                                </a>
                            </h2>

                            @if ($post->description)
                                <p class="mt-2 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                                    {{ $post->description }}
                                </p>
                            @endif

                            @if ($post->author)
                                <p class="mt-3 font-mono text-[11px] tracking-[0.18em] text-muted-foreground/80 uppercase">
                                    {{ $post->author }}
                                </p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-layouts.blog>
