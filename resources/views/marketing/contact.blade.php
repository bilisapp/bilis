<x-layouts.marketing
    title="Contact"
    description="Ask about a Team plan, report something broken, or just ask a question. A form and an email address — no chat widget, no JavaScript."
    current="contact"
>
    {{-- The commercial surface, such as it is.

         There is no checkout, so this page is where "I need more than Free"
         goes. It is Blade with no JavaScript on purpose: it has to work for
         someone who has not signed up, on a locked-down browser, on a phone,
         first try. The form posts, the row is written, and the mail is only a
         notification. --}}
    <section class="mx-auto max-w-5xl px-6 pt-16 pb-12 sm:pt-20">
        <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Contact</p>

        <h1 class="mt-4 max-w-3xl text-3xl leading-tight font-semibold tracking-tight sm:text-4xl">
            Tell us what you run.
        </h1>

        <p class="mt-5 max-w-2xl text-sm leading-relaxed text-muted-foreground sm:text-base">
            Bilis is small enough that a person reads everything sent here. If you need more than the
            Free plan, say roughly how many services you ship from and how long you need to keep the
            data, and we come back with a number rather than a price list. If something is broken,
            the more specific you are the faster it gets fixed.
        </p>
    </section>

    <section class="border-y border-border bg-card">
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            @include('marketing.partials.section-label', ['number' => '01', 'label' => 'Write to us'])

            @if ($sent)
                {{-- The thank-you replaces the form rather than sitting above
                     it: the message is recorded, and offering the same form
                     again invites a duplicate. --}}
                <div class="mt-6 max-w-2xl rounded-xl border border-border bg-background p-6"
                     data-test="contact-sent">
                    <h2 class="text-xl font-semibold tracking-tight">Got it.</h2>

                    <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                        Your message is recorded and on its way to us. We answer from
                        <a href="mailto:{{ config('legal.contact.general') }}"
                           class="underline underline-offset-2 hover:text-foreground">{{ config('legal.contact.general') }}</a>,
                        usually within a working day — check the spam folder if it looks quiet.
                    </p>

                    <div class="mt-6 flex flex-wrap items-center gap-3">
                        <a href="{{ route('pricing') }}"
                           class="rounded-md border border-border px-5 py-2.5 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground">
                            Back to pricing
                        </a>
                        <a href="{{ route('contact.show') }}"
                           class="text-sm text-muted-foreground underline underline-offset-2 transition-colors hover:text-foreground">
                            Send another
                        </a>
                    </div>
                </div>
            @else
                <form method="POST"
                      action="{{ route('contact.store') }}"
                      class="mt-6 max-w-2xl">
                    @csrf

                    <div class="grid gap-5">
                        <div class="grid gap-2">
                            <label for="contact-topic"
                                   class="text-sm font-medium">What is this about?</label>
                            <select id="contact-topic"
                                    name="topic"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                                @foreach ($topics as $topic)
                                    <option value="{{ $topic->value }}"
                                            @selected(old('topic', $selectedTopic->value) === $topic->value)>{{ $topic->label() }}</option>
                                @endforeach
                            </select>
                            @error('topic')
                                <p class="text-sm text-severity-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid gap-2 sm:grid-cols-2 sm:gap-4">
                            <div class="grid gap-2">
                                <label for="contact-name"
                                       class="text-sm font-medium">Your name</label>
                                <input id="contact-name"
                                       name="name"
                                       type="text"
                                       maxlength="120"
                                       required
                                       value="{{ old('name', $prefillName) }}"
                                       class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                                @error('name')
                                    <p class="text-sm text-severity-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="grid gap-2">
                                <label for="contact-email"
                                       class="text-sm font-medium">Email</label>
                                <input id="contact-email"
                                       name="email"
                                       type="email"
                                       required
                                       value="{{ old('email', $prefillEmail) }}"
                                       class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                                @error('email')
                                    <p class="text-sm text-severity-error">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div class="grid gap-2">
                            <label for="contact-message"
                                   class="text-sm font-medium">Message</label>
                            <textarea id="contact-message"
                                      name="message"
                                      rows="7"
                                      maxlength="5000"
                                      required
                                      class="w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-sm leading-relaxed"
                                      placeholder="Six services, about 40 million log lines a week, and we need 90 days of retention.">{{ old('message') }}</textarea>
                            @error('message')
                                <p class="text-sm text-severity-error">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- The honeypot. Hidden from people and from screen
                             readers, never validated, and a filled one is
                             answered exactly like a successful send. --}}
                        <div class="hidden"
                             aria-hidden="true">
                            <label for="contact-website">Website</label>
                            <input id="contact-website"
                                   name="website"
                                   type="text"
                                   tabindex="-1"
                                   autocomplete="off"
                                   value="">
                        </div>

                        <div class="flex flex-wrap items-center gap-4">
                            <button type="submit"
                                    class="rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90">
                                Send message
                            </button>

                            <p class="text-xs leading-relaxed text-muted-foreground">
                                Or email
                                <a href="mailto:{{ config('legal.contact.general') }}"
                                   class="underline underline-offset-2 hover:text-foreground">{{ config('legal.contact.general') }}</a>
                                directly.
                            </p>
                        </div>
                    </div>
                </form>
            @endif
        </div>
    </section>

    <section>
        <div class="mx-auto max-w-5xl px-6 py-16 sm:py-20">
            @include('marketing.partials.section-label', ['number' => '02', 'label' => 'The other addresses'])

            <h2 class="mt-4 text-xl font-semibold tracking-tight">Some things have a better door</h2>

            <ul class="mt-6 max-w-2xl divide-y divide-border border-t border-border">
                <li class="grid gap-1 py-4">
                    <span class="text-sm font-medium">A security issue</span>
                    <span class="text-sm leading-relaxed text-muted-foreground">
                        <a href="mailto:{{ config('legal.contact.security') }}"
                           class="underline underline-offset-2 hover:text-foreground">{{ config('legal.contact.security') }}</a>,
                        and the disclosure policy is in
                        <a href="{{ config('bilis.github_url') }}/blob/main/SECURITY.md"
                           class="underline underline-offset-2 hover:text-foreground"
                           target="_blank"
                           rel="noopener noreferrer">SECURITY.md</a>. Please do not open a public issue for it.
                    </span>
                </li>
                <li class="grid gap-1 py-4">
                    <span class="text-sm font-medium">Your personal data</span>
                    <span class="text-sm leading-relaxed text-muted-foreground">
                        <a href="mailto:{{ config('legal.contact.privacy') }}"
                           class="underline underline-offset-2 hover:text-foreground">{{ config('legal.contact.privacy') }}</a>
                        — access, export and deletion requests, and everything else in the
                        <a href="{{ route('privacy') }}"
                           class="underline underline-offset-2 hover:text-foreground">privacy policy</a>.
                    </span>
                </li>
                <li class="grid gap-1 py-4">
                    <span class="text-sm font-medium">A bug, or a feature you want</span>
                    <span class="text-sm leading-relaxed text-muted-foreground">
                        The
                        <a href="{{ config('bilis.github_url') }}/issues"
                           class="underline underline-offset-2 hover:text-foreground"
                           target="_blank"
                           rel="noopener noreferrer">issue tracker</a>
                        is public, and an issue is easier to follow than an email thread.
                    </span>
                </li>
            </ul>
        </div>
    </section>
</x-layouts.marketing>
