<x-layouts.marketing
    title="Pricing"
    description="Free on bilis.app, with published limits and no card. Anything larger is a conversation rather than a checkout — and self-hosting stays first-class and free."
    current="pricing"
>
    {{-- The page a visitor reads before deciding whether to sign up.

         Same grammar as /features — numbered mono labels, hairline dividers,
         alternating card bands — because a pricing page written in a
         different voice reads as if a different company wrote it. Every
         number comes from PlanLimits through $free; nothing here is a
         literal, so the page and the meters inside the app cannot disagree. --}}
    <section class="mx-auto max-w-5xl px-6 pt-16 pb-12 sm:pt-20">
        <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Pricing</p>

        <h1 class="mt-4 max-w-3xl text-3xl leading-tight font-semibold tracking-tight sm:text-4xl">
            Free on bilis.app. Free on your own box.
        </h1>

        <p class="mt-5 max-w-2xl text-sm leading-relaxed text-muted-foreground sm:text-base">
            There is one hosted plan today and it costs nothing: a published set of limits, no card,
            and no trial that expires into a bill. Teams that outgrow it talk to us and we size
            something — there is no checkout to click, and no price list to reverse-engineer.
            Running Bilis on your own hardware has no plan at all and never will.
        </p>
    </section>

    {{-- 01 — The two plans --}}
    <section id="plans"
             class="scroll-mt-20 border-y border-border bg-card">
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            @include('marketing.partials.section-label', ['number' => '01', 'label' => 'The plans'])

            <h2 class="mt-4 text-xl font-semibold tracking-tight">One that is free, and one that is a
                conversation</h2>

            <div class="mt-8 grid gap-6 lg:grid-cols-2">
                {{-- Free --}}
                <div class="flex flex-col rounded-xl border border-border bg-background p-6"
                     data-test="pricing-free">
                    <div class="flex items-baseline justify-between gap-3">
                        <h3 class="text-lg font-semibold tracking-tight">Free</h3>
                        <span class="font-mono text-sm text-muted-foreground">&euro;0 — no card</span>
                    </div>

                    <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                        Enough room to run a real service on, not a demo. Everything the product does
                        is in here — logs, traces, the viewer, autofix, the dashboard.
                    </p>

                    <ul class="mt-6 divide-y divide-border border-t border-border">
                        @foreach ([
                            [number_format($free['projectsPerTeam']).' projects', 'per team — one project per application you ship from.'],
                            [number_format($free['membersPerTeam']).' members', 'per team, the owner included.'],
                            [number_format($free['eventsPerDay']).' events a day', 'log records plus spans, counted across the team from 00:00 UTC.'],
                            [number_format($free['retentionDays']).'-day retention', 'for logs and spans, then deleted automatically.'],
                            [number_format($free['requestsPerMinute']).' requests a minute', 'per API key on the ingest endpoints. A batching exporter sends thousands of records per request.'],
                            ['Every feature', 'no capability is held back for a paid tier that does not exist yet.'],
                        ] as [$fact, $detail])
                            <li class="grid gap-1 py-4">
                                <span class="text-sm font-medium">{{ $fact }}</span>
                                <span class="text-sm leading-relaxed text-muted-foreground">{{ $detail }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <p class="mt-6 rounded-lg border border-border px-4 py-3 text-sm leading-relaxed text-muted-foreground">
                        <span class="text-foreground">These limits are soft.</span> Going over does not
                        drop a log line, reject a span, or block a button. The dashboard shows you where
                        you stand and starts saying so at
                        {{ $free['warnAtPercent'] }}% — that is the whole enforcement story, on purpose.
                    </p>

                    <div class="mt-6">
                        @auth
                            @if ($team = auth()->user()->currentTeam)
                                <a href="{{ route('dashboard', $team) }}"
                                   class="inline-block rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90">
                                    Open the dashboard
                                </a>
                            @endif
                        @else
                            <a href="{{ route('register') }}"
                               class="inline-block rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90">
                                Create an account
                            </a>
                        @endauth
                    </div>
                </div>

                {{-- Team --}}
                <div class="flex flex-col rounded-xl border border-border bg-background p-6"
                     data-test="pricing-team">
                    <div class="flex items-baseline justify-between gap-3">
                        <h3 class="text-lg font-semibold tracking-tight">Team</h3>
                        <span class="font-mono text-sm text-muted-foreground">Contact us</span>
                    </div>

                    <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                        For a team whose volume, retention or headcount has outgrown the numbers on the
                        left. Same product, more room, and a person who answers.
                    </p>

                    <ul class="mt-6 divide-y divide-border border-t border-border">
                        @foreach ([
                            ['More projects and members', 'as many as your team actually has.'],
                            ['Longer retention', 'when '.number_format($free['retentionDays']).' days is not enough to close an incident.'],
                            ['Higher volume', 'sized to what you send rather than to a tier boundary.'],
                            ['Priority support', 'a direct line rather than the general inbox.'],
                        ] as [$fact, $detail])
                            <li class="grid gap-1 py-4">
                                <span class="text-sm font-medium">{{ $fact }}</span>
                                <span class="text-sm leading-relaxed text-muted-foreground">{{ $detail }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <p class="mt-6 rounded-lg border border-border px-4 py-3 text-sm leading-relaxed text-muted-foreground">
                        <span class="text-foreground">No price list yet.</span> We are sizing it with the
                        first teams rather than guessing at a table of tiers. Tell us what you run and we
                        come back with a number.
                    </p>

                    <div class="mt-6">
                        <a href="{{ route('contact.show', ['topic' => 'upgrade']) }}"
                           class="inline-block rounded-md border border-border px-5 py-2.5 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground">
                            Tell us what you run
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- 02 — Self-hosting --}}
    <section id="self-hosting"
             class="scroll-mt-20">
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            @include('marketing.partials.section-label', ['number' => '02', 'label' => 'Self-hosting'])

            <h2 class="mt-4 text-xl font-semibold tracking-tight">Self-hosting stays first-class</h2>

            <p class="mt-3 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                The hosted service is a convenience, not the product's real home. Bilis is source
                available under the
                <a href="{{ config('bilis.github_url') }}/blob/main/LICENSE.md"
                   class="underline underline-offset-2 hover:text-foreground"
                   target="_blank"
                   rel="noopener noreferrer">Functional Source License</a>,
                and an instance you run yourself has <span class="text-foreground">no plan, no
                    allowance and no meter</span> — none of the numbers above are read by an instance
                that is not ours. What retention costs you there is disk you already pay for.
            </p>

            <div class="mt-8 flex flex-wrap items-center gap-3">
                <a href="{{ route('docs.show', ['section' => 'reference', 'page' => 'limits-and-behavior']) }}"
                   class="rounded-md border border-border px-5 py-2.5 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground">
                    Limits and behaviour
                </a>
                <a href="{{ config('bilis.github_url') }}"
                   class="flex items-center gap-2 rounded-md border border-border px-5 py-2.5 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground"
                   target="_blank"
                   rel="noopener noreferrer">
                    <x-icons.github class="size-4" />
                    Read the source
                </a>
            </div>
        </div>
    </section>

    {{-- 03 — Questions --}}
    <section id="questions"
             class="scroll-mt-20 border-y border-border bg-card">
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            @include('marketing.partials.section-label', ['number' => '03', 'label' => 'Questions'])

            <h2 class="mt-4 text-xl font-semibold tracking-tight">The five we actually get asked</h2>

            <dl class="mt-8 max-w-3xl divide-y divide-border border-t border-border">
                @foreach ([
                    [
                        'What counts as an event?',
                        'One log record or one span. A trace made of forty spans is forty events; a batch of five thousand log lines in one POST is five thousand. The count runs from 00:00 UTC and the dashboard breaks it into logs and spans so you can see which side is loud.',
                    ],
                    [
                        'What happens when I go over?',
                        'Nothing is dropped, nothing is rejected, and nothing is switched off. The meter on your dashboard turns and says so, and we get in touch if it stays there. Deleting telemetry to enforce a quota loses exactly the data you are about to need.',
                    ],
                    [
                        'Can I pay you now?',
                        'Not yet — there is no checkout, and nothing on this page is purchasable. Tell us what you run and we will agree a plan directly.',
                    ],
                    [
                        'Where is my data stored?',
                        'On '.config('legal.hosting.provider').' hardware in '.config('legal.hosting.country').'. The details, the sub-processors and the retention promise are in the privacy policy.',
                    ],
                    [
                        'Can I leave?',
                        'Yes, whenever, and without asking. Your logs and spans are yours, the terms say so, and the same software runs on your own box under a licence we do not get to revoke.',
                    ],
                ] as [$question, $answer])
                    <div class="grid gap-1 py-5">
                        <dt class="text-sm font-medium">{{ $question }}</dt>
                        <dd class="text-sm leading-relaxed text-muted-foreground">{{ $answer }}</dd>
                    </div>
                @endforeach
            </dl>

            <p class="mt-8 text-sm leading-relaxed text-muted-foreground">
                Anything else:
                <a href="{{ route('contact.show') }}"
                   class="underline underline-offset-2 hover:text-foreground">write to us</a>,
                or read the
                <a href="{{ route('terms') }}"
                   class="underline underline-offset-2 hover:text-foreground">terms</a>
                and the
                <a href="{{ route('privacy') }}"
                   class="underline underline-offset-2 hover:text-foreground">privacy policy</a>.
            </p>
        </div>
    </section>
</x-layouts.marketing>
