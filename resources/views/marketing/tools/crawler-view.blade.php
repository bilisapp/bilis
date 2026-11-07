@php
    /** @var \App\Services\Tools\CrawlerView|null $view */
    $verdicts = [
        'server' => ['class' => 'text-severity-debug', 'label' => 'The content is in the HTML', 'note' => 'A crawler that never runs JavaScript still reads this page.'],
        'thin' => ['class' => 'text-severity-warn', 'label' => 'Thin without JavaScript', 'note' => 'Little text arrives in the HTML. If the page has more to say than this, a script is rendering it.'],
        'client' => ['class' => 'text-severity-error', 'label' => 'Rendered by JavaScript', 'note' => 'Without a script the page is close to empty. Only a crawler that renders reads it.'],
    ];
@endphp

<x-tools.page route="tools.crawler-view"
              title="See a page without JavaScript — how crawlers see your site"
              description="Fetch any URL the way a crawler does, with no JavaScript, and see the text, headings, links, meta tags and structured data that arrive in the HTML — as Googlebot, Bingbot, GPTBot or ClaudeBot."
              heading="See your page the way a crawler does"
              intro="Enter a URL. It is fetched once, the way a crawler fetches it — no JavaScript, no cookies — and you see what is actually in the HTML: the text in reading order, the headings, the links a crawler can follow and the tags a search result is built from.">
    <form method="GET"
          action="{{ route('tools.crawler-view') }}"
          data-tool-form="submit"
          class="grid gap-6">
        <div class="grid gap-4 sm:grid-cols-[1fr_14rem]">
            <div class="grid content-start gap-2">
                <label for="crawler-url"
                       class="text-sm font-medium">Page URL</label>
                <input id="crawler-url"
                       name="url"
                       type="text"
                       inputmode="url"
                       autocomplete="url"
                       spellcheck="false"
                       placeholder="https://example.com/pricing"
                       value="{{ $url }}"
                       class="w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-sm">
            </div>
            <div class="grid content-start gap-2">
                <label for="crawler-agent"
                       class="text-sm font-medium">Send as</label>
                <select id="crawler-agent"
                        name="agent"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                    @foreach ($agents as $key => $option)
                        <option value="{{ $key }}"
                                @selected($agent === $key)>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div>
            <button type="submit"
                    data-tool-submit
                    class="rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90">
                Fetch without JavaScript
            </button>
        </div>

        <div data-tool-result
             aria-live="polite"
             class="grid gap-6">
            @if ($error !== null)
                <p class="rounded-lg border border-border bg-background px-4 py-3 text-sm text-severity-error"
                   data-test="crawler-error">{{ $error }}</p>
            @elseif ($view !== null)
                @php($verdict = $verdicts[$view->verdict()])

                <div class="rounded-xl border border-border bg-background p-5"
                     data-test="crawler-verdict-{{ $view->verdict() }}">
                    <p class="text-base font-semibold {{ $verdict['class'] }}">{{ $verdict['label'] }}</p>
                    <p class="mt-1 text-sm text-muted-foreground">{{ $verdict['note'] }}</p>

                    <dl class="mt-5 grid grid-cols-2 gap-x-6 gap-y-4 text-sm sm:grid-cols-4">
                        @foreach ([
                            ['Status', (string) $view->page->status],
                            ['Words in the HTML', number_format($view->words)],
                            ['HTML size', number_format($view->page->bytes / 1024, 1).' KB'],
                            ['Fetched in', number_format($view->page->elapsedMs).' ms'],
                            ['Scripts', $view->scripts['external'].' external · '.$view->scripts['inline'].' inline'],
                            ['Links', $view->links['internal'].' internal · '.$view->links['external'].' external'],
                            ['Images', $view->images['total'].($view->images['missingAlt'] > 0 ? ' · '.$view->images['missingAlt'].' without alt' : '')],
                            ['Built with', $view->frameworks === [] ? '—' : implode(', ', $view->frameworks)],
                        ] as [$label, $value])
                            <div>
                                <dt class="text-xs text-muted-foreground">{{ $label }}</dt>
                                <dd class="mt-1 font-mono text-sm break-words">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    @if ($view->page->redirects !== [])
                        <ol class="mt-5 grid gap-1 border-t border-border pt-4 font-mono text-xs text-muted-foreground">
                            @foreach ($view->page->redirects as $redirect)
                                <li class="break-all">{{ $redirect['status'] }} {{ $redirect['url'] }} →</li>
                            @endforeach
                            <li class="break-all text-foreground">{{ $view->page->status }} {{ $view->page->finalUrl }}</li>
                        </ol>
                    @endif
                </div>

                <div class="grid gap-2">
                    <h2 class="text-sm font-medium">As a search result</h2>
                    <div class="rounded-xl border border-border bg-background p-5">
                        <p class="truncate font-mono text-xs text-muted-foreground">{{ $view->page->finalUrl }}</p>
                        <p class="mt-1 text-lg leading-snug">{{ $view->title !== '' ? \Illuminate\Support\Str::limit($view->title, 65) : 'No title in the HTML' }}</p>
                        <p class="mt-1 text-sm leading-relaxed text-muted-foreground">{{ $view->description !== '' ? \Illuminate\Support\Str::limit($view->description, 160) : 'No meta description — the search engine would pick a snippet itself.' }}</p>
                    </div>
                </div>

                @if ($view->findings() !== [])
                    <div class="grid gap-2">
                        <h2 class="text-sm font-medium">Worth a look</h2>
                        <ul class="grid gap-2 text-sm"
                            data-test="crawler-findings">
                            @foreach ($view->findings() as $finding)
                                <li class="flex gap-3 rounded-lg border border-border bg-background px-4 py-3">
                                    <span class="shrink-0 font-mono text-xs leading-5 uppercase {{ $finding['level'] === 'error' ? 'text-severity-error' : 'text-severity-warn' }}">{{ $finding['level'] === 'error' ? 'Error' : 'Warn' }}</span>
                                    <span>{{ $finding['text'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="grid gap-2">
                    <h2 class="text-sm font-medium">In the &lt;head&gt;</h2>
                    <dl class="grid gap-px overflow-hidden rounded-xl border border-border bg-border sm:grid-cols-2">
                        @foreach ([
                            ['Title', $view->title],
                            ['Meta description', $view->description],
                            ['Canonical', $view->canonical],
                            ['Robots', trim($view->robots.($view->page->robotsHeader !== '' ? ' · X-Robots-Tag: '.$view->page->robotsHeader : ''), ' ·')],
                            ['Language', $view->lang],
                            ['Structured data', implode(', ', $view->structuredDataTypes)],
                            ['og:title', $view->ogTitle],
                            ['og:image', $view->ogImage],
                        ] as [$label, $value])
                            <div class="bg-background p-4">
                                <dt class="text-xs text-muted-foreground">{{ $label }}</dt>
                                <dd class="mt-1 font-mono text-sm break-all {{ $value === '' ? 'text-muted-foreground' : '' }}">{{ $value !== '' ? $value : 'none' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                @if ($view->headings !== [])
                    <div class="grid gap-2">
                        <h2 class="text-sm font-medium">Headings outline</h2>
                        <ol class="grid gap-1 rounded-xl border border-border bg-background p-5 text-sm">
                            @foreach ($view->headings as $heading)
                                <li class="flex gap-3 {{ ['', 'pl-5', 'pl-10', 'pl-15', 'pl-20', 'pl-25'][$heading['level'] - 1] }}">
                                    <span class="shrink-0 font-mono text-xs leading-5 text-muted-foreground">h{{ $heading['level'] }}</span>
                                    <span class="{{ $heading['text'] === '' ? 'text-muted-foreground italic' : '' }}">{{ $heading['text'] !== '' ? $heading['text'] : 'empty' }}</span>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                @endif

                <div class="grid gap-2">
                    <h2 class="text-sm font-medium">What the crawler reads</h2>
                    <div class="max-h-[36rem] overflow-y-auto rounded-xl border border-border bg-background p-5"
                         data-test="crawler-text">
                        @forelse ($view->blocks as $block)
                            @if (str_starts_with($block['tag'], 'h') && strlen($block['tag']) === 2)
                                <p class="mt-4 font-semibold first:mt-0"><span class="mr-2 font-mono text-xs font-normal text-muted-foreground">{{ $block['tag'] }}</span>{{ $block['text'] }}</p>
                            @elseif ($block['tag'] === 'img')
                                <p class="mt-2 font-mono text-xs text-muted-foreground">[image{{ $block['text'] !== '' ? ': '.$block['text'] : ', no alt' }}]</p>
                            @elseif ($block['tag'] === 'li')
                                <p class="mt-1 pl-4 text-sm leading-relaxed">· {{ $block['text'] }}</p>
                            @else
                                <p class="mt-2 text-sm leading-relaxed">{{ $block['text'] }}</p>
                            @endif
                        @empty
                            <p class="text-sm text-muted-foreground">Nothing. The body holds no readable text before JavaScript runs.</p>
                        @endforelse

                        @if ($view->blocksTruncated)
                            <p class="mt-4 text-xs text-muted-foreground">The first {{ \App\Services\Tools\CrawlerView::MAX_BLOCKS }} blocks are shown; the word count covers the whole page.</p>
                        @endif
                    </div>
                </div>

                @if ($view->noscript !== '')
                    <div class="grid gap-2">
                        <h2 class="text-sm font-medium">The &lt;noscript&gt; fallback</h2>
                        <p class="rounded-xl border border-border bg-background p-5 text-sm leading-relaxed">{{ \Illuminate\Support\Str::limit($view->noscript, 600) }}</p>
                    </div>
                @endif
            @endif
        </div>
    </form>

    <x-slot:explainer>
        <h2>Why look at a page without JavaScript</h2>
        <p>
            A browser runs the page's scripts before you see anything, so what you see is not what was sent. A
            crawler starts from what was sent. If the words, links and tags are in that first HTML response, every
            crawler reads them. If a script builds them afterwards, only a crawler that renders will — and later,
            if at all.
        </p>

        <h2>Who renders and who does not</h2>
        <ul>
            <li><strong>Googlebot</strong> renders JavaScript, but in a second pass: the HTML is crawled first and the page is queued for rendering. Content that only exists after rendering is indexed later, and a script that fails or times out leaves the page as this tool shows it.</li>
            <li><strong>Bingbot</strong> renders too, with fewer resources to spend on it; Microsoft's own guidance is to serve the content in the HTML.</li>
            <li><strong>AI crawlers</strong> — GPTBot, ClaudeBot, PerplexityBot and the like — fetch HTML and, by every public measurement so far, do not run JavaScript. A page that is empty here is empty to them.</li>
            <li><strong>Link previews</strong> in Slack, Discord, X, LinkedIn and messaging apps read the Open Graph tags from the raw HTML and nothing else.</li>
        </ul>

        <h2>Reading the result</h2>
        <ul>
            <li><strong>Rendered by JavaScript</strong> — under 50 words next to an empty mount point such as <code>&lt;div id="app"&gt;&lt;/div&gt;</code> or a <code>&lt;noscript&gt;</code> message. A single-page app without server-side rendering looks like this.</li>
            <li><strong>Thin</strong> — under 200 words with a script bundle on the page. Either the page really is short, or its shell is rendered on the server and its content fetched in the browser; the text below tells you which.</li>
            <li><strong>In the HTML</strong> — the content arrived with the response. Check the outline and the text below it say what the page is about.</li>
        </ul>

        <h2>How to fix a page that is empty here</h2>
        <p>
            Render the content on the server: server-side rendering or static generation in Next.js, Nuxt, SvelteKit
            or Astro, or plain server templates. Put the title, description, canonical and Open Graph tags in the
            server response rather than setting them from a script, and make every navigation link a real
            <code>&lt;a href&gt;</code>. Pre-rendering for bots only is a stopgap: two versions of a page drift apart.
        </p>

        <h2>What this tool does not do</h2>
        <p>
            It makes one GET request from our server, with no cookies, and reads at most
            {{ number_format(\App\Services\Tools\PageFetcher::MAX_BYTES / 1_000_000) }} MB of HTML. It does not run
            JavaScript, check <code>robots.txt</code> or fetch the page's CSS and images. A site that blocks data-centre
            addresses or verifies crawlers by reverse DNS will answer differently than it does to the real crawler.
            Only public addresses on ports 80 and 443 are fetched, and a result is cached for ten minutes.
        </p>
        <p>
            The public pages of <a href="{{ route('home') }}">Bilis</a> are plain server-rendered HTML for exactly
            this reason — run this tool on any of them.
        </p>
    </x-slot:explainer>
</x-tools.page>
