<x-layouts.marketing
    title="MCP for your agent"
    description="Connect Claude, Cursor or any MCP client to your Bilis instance in one line. Your agent reads the logs and traces of the app it is editing — read-only, over OAuth, with nothing leaving your box."
    current="features"
>
    {{-- The page for the reader who already debugs through an agent.

         Same grammar as /features — mono section labels, hairline dividers,
         alternating card bands — because it is the same site making the same
         kind of claim. The one command is the hero: everything else on the
         page exists to make copying it feel safe. --}}
    <section class="mx-auto max-w-5xl px-6 pt-16 pb-12 sm:pt-20">
        <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">MCP</p>

        <h1 class="mt-4 max-w-3xl text-3xl leading-tight font-semibold tracking-tight sm:text-4xl">
            Your agent is already in the code. Give it the logs.
        </h1>

        <p class="mt-5 max-w-2xl text-sm leading-relaxed text-muted-foreground sm:text-base">
            When something breaks, the assistant you are already talking to has to be handed a
            screenshot. Connect it to Bilis instead and it reads the errors itself: which lines
            failed, how often, and the whole request that produced them — while it is looking at
            the code that produced it. One line to connect, and no key to paste.
        </p>

        {{-- The command, copyable. The button is hidden until the marketing
             bundle reveals it, so a reader without JavaScript never sees a
             control that does nothing. --}}
        <div class="mt-10 overflow-hidden rounded-lg border border-border bg-background">
            <div class="flex items-center justify-between gap-4 border-b border-border px-4 py-2">
                <span class="font-mono text-[11px] tracking-[0.14em] text-muted-foreground uppercase">Claude Code</span>

                <button type="button"
                        hidden
                        data-copy="mcp-connect"
                        class="-mr-1.5 rounded px-1.5 py-0.5 font-mono text-[11px] tracking-[0.14em] text-muted-foreground uppercase transition-colors hover:bg-accent hover:text-accent-foreground">
                    <span data-copy-idle>Copy</span>
                    <span data-copy-done hidden class="text-severity-debug">Copied</span>
                </button>
            </div>

            <div class="scrollbar-stream overflow-x-auto px-4 py-5">
<pre id="mcp-connect"
     class="font-mono text-xs leading-relaxed"
     data-copy-text='claude mcp add --transport http bilis https://bilis.example.com/mcp'><span class="text-muted-foreground"># One line. A browser opens, you sign in, you approve.</span>
claude mcp add --transport http bilis <span class="text-severity-debug">https://bilis.example.com/mcp</span>

<span class="text-muted-foreground"># Claude Desktop, Cursor, anything else: the same URL in mcpServers.</span>
{"mcpServers":{"bilis":{"url":<span class="text-severity-debug">"https://bilis.example.com/mcp"</span>}}}</pre>
            </div>
        </div>

        <p class="mt-4 text-xs leading-relaxed text-muted-foreground">
            Swap the host for your own instance. Self-hosted Bilis is free and has no plan;
            the server is part of the app, with nothing extra to run.
        </p>
    </section>

    {{-- 01 — what it changes --}}
    <section id="conversation"
             class="border-t border-border bg-card">
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            @include('marketing.partials.section-label', ['number' => '01', 'label' => 'What changes'])

            <h2 class="mt-4 max-w-3xl text-2xl leading-tight font-semibold tracking-tight sm:text-3xl">
                From "it's broken" to the failing span, without leaving the chat.
            </h2>

            <p class="mt-5 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                The agent asks Bilis the questions you would have asked it yourself, in the order
                you would have asked them.
            </p>

            <ol class="mt-8 divide-y divide-border border-t border-b border-border">
                @foreach ([
                    [
                        'ask' => '"Checkout has been 500ing since the deploy."',
                        'does' => 'Groups the last hour\'s errors into distinct problems and names the one that started when the deploy did — with a count, not a wall of duplicate lines.',
                        'tool' => 'error-summary',
                    ],
                    [
                        'ask' => '"Show me one of those requests."',
                        'does' => 'Opens the whole request as a waterfall: every service it touched, how long each step took, and which one failed first.',
                        'tool' => 'get-trace',
                    ],
                    [
                        'ask' => '"Was it slow before that too?"',
                        'does' => 'Reads p95 and p99 per service over whatever window you name, so a regression is a number rather than a feeling.',
                        'tool' => 'service-latency',
                    ],
                    [
                        'ask' => '"What was the app logging just before it?"',
                        'does' => 'Reads the lines around the failure in order — usually more informative than the failure itself.',
                        'tool' => 'search-logs',
                    ],
                ] as $step)
                    <li class="grid gap-2 py-5 sm:grid-cols-[minmax(0,18rem)_1fr] sm:gap-8">
                        <p class="text-sm font-medium">{{ $step['ask'] }}</p>

                        <div class="min-w-0">
                            <p class="text-sm leading-relaxed text-muted-foreground">{{ $step['does'] }}</p>
                            <p class="mt-2 font-mono text-[11px] tracking-[0.14em] text-muted-foreground/70 uppercase">
                                {{ $step['tool'] }}
                            </p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- 02 — the boundary --}}
    <section id="boundary"
             class="border-t border-border">
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            @include('marketing.partials.section-label', ['number' => '02', 'label' => 'Where the line is'])

            <h2 class="mt-4 max-w-3xl text-2xl leading-tight font-semibold tracking-tight sm:text-3xl">
                It can read. That is all it can do.
            </h2>

            <div class="mt-8 grid gap-10 lg:grid-cols-2 lg:gap-16">
                <div>
                    <p class="text-sm leading-relaxed text-muted-foreground">
                        Handing an agent a credential to your observability stack is a real decision,
                        so the answer is not "trust the prompt". The connection is read-only at the
                        server: there is no tool that writes, and none that could be talked into it.
                    </p>

                    <p class="mt-4 text-sm leading-relaxed text-muted-foreground">
                        You sign in yourself, in your own browser, and approve the connection on a
                        screen that names the client. Access lasts a day and refreshes for a month
                        while the client keeps using it. No API key is ever copied, shown to the
                        agent, or created by it.
                    </p>

                    <p class="mt-4 text-sm leading-relaxed text-muted-foreground">
                        And the data stays where it was: your instance answers the questions, so the
                        logs never travel anywhere they were not already.
                    </p>
                </div>

                <ul class="divide-y divide-border border-t border-b border-border">
                    @foreach ([
                        ['can' => true, 'text' => 'Search logs and read traces for the teams you belong to'],
                        ['can' => true, 'text' => 'List your teams, projects and the services they send'],
                        ['can' => false, 'text' => 'Send, edit or delete anything'],
                        ['can' => false, 'text' => 'Create a project, or read or issue an API key'],
                        ['can' => false, 'text' => 'Change a setting, or start an Autofix job'],
                    ] as $line)
                        <li class="flex items-baseline gap-3 py-3">
                            <span aria-hidden="true"
                                  class="font-mono text-xs text-muted-foreground">{{ $line['can'] ? '+' : '—' }}</span>
                            <span @class([
                                'text-sm',
                                'text-muted-foreground' => ! $line['can'],
                            ])>{{ $line['text'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </section>

    {{-- 03 — the toolbox --}}
    <section id="tools"
             class="border-t border-border bg-card">
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            @include('marketing.partials.section-label', ['number' => '03', 'label' => 'The toolbox'])

            <h2 class="mt-4 max-w-3xl text-2xl leading-tight font-semibold tracking-tight sm:text-3xl">
                Eight tools, two prompts, no surprises.
            </h2>

            <dl class="mt-8 divide-y divide-border border-t border-b border-border">
                @foreach ([
                    ['list-teams', 'The teams you belong to, and which one the tools default to.'],
                    ['list-projects', 'A team\'s projects, and whether each has ever received anything.'],
                    ['list-services', 'The service names a project actually sends, so a filter is never a guess.'],
                    ['search-logs', 'Log lines over a window, by service, severity, text, trace or span.'],
                    ['error-summary', 'A window\'s errors folded into distinct problems, with counts.'],
                    ['list-traces', 'Traces over a window: duration, span count, error count.'],
                    ['get-trace', 'One request as a waterfall, with the attributes that locate a bug.'],
                    ['service-latency', 'p95 and p99 per service, slowest first.'],
                ] as [$name, $what])
                    <div class="grid gap-1 py-3.5 sm:grid-cols-[minmax(0,12rem)_1fr] sm:gap-8">
                        <dt class="font-mono text-xs text-foreground">{{ $name }}</dt>
                        <dd class="text-sm text-muted-foreground">{{ $what }}</dd>
                    </div>
                @endforeach
            </dl>

            <p class="mt-6 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                Plus two prompts your client can pull in: one that teaches a fresh assistant how to
                wire an app up to send here, and one that turns a reported symptom into a plan
                across the tools above.
            </p>
        </div>
    </section>

    {{-- Close --}}
    <section class="border-t border-border">
        <div class="mx-auto flex max-w-5xl flex-col gap-6 px-6 py-16 sm:flex-row sm:items-center sm:justify-between sm:py-20">
            <div>
                <h2 class="text-2xl leading-tight font-semibold tracking-tight">
                    Connect it and ask it something.
                </h2>
                <p class="mt-3 max-w-xl text-sm leading-relaxed text-muted-foreground">
                    The guide has the exact configuration for every client, and what to set when you
                    are running Bilis yourself.
                </p>
            </div>

            <div class="flex flex-wrap gap-3">
                <a href="{{ route('docs.show', ['section' => 'reference', 'page' => 'mcp']) }}"
                   class="inline-flex h-10 items-center justify-center rounded-md bg-foreground px-5 text-sm font-medium text-background transition-colors hover:bg-foreground/90">
                    Read the guide
                </a>

                <a href="{{ route('register') }}"
                   class="inline-flex h-10 items-center justify-center rounded-md border border-border bg-background px-5 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground">
                    Create an account
                </a>
            </div>
        </div>
    </section>

    @push('scripts')
        {{-- The marketing bundle, for the copy button. Still no Inertia. --}}
        @vite('resources/js/marketing/marketing.ts')
    @endpush
</x-layouts.marketing>
