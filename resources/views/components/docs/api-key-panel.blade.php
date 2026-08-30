@props([
    'placeholder' => \App\Http\Controllers\DocsController::API_KEY_PLACEHOLDER,
    'sampleEndpoint' => 'https://bilis.example.com',
    'projects' => [],
])

@php
    /** @var array<int, array{slug: string, name: string}> $projects */
    $team = auth()->user()?->currentTeam;
@endphp

{{--
    The code on this page contains a placeholder key. For a signed-in reader,
    turn it into a real one: create a project (or pick an existing one), issue
    a key, and rewrite the samples below in place.
--}}
<section data-docs-api-key
         data-placeholder="{{ $placeholder }}"
         data-sample-endpoint="{{ $sampleEndpoint }}"
         @auth @if ($team) data-endpoint="{{ route('docs.api-key') }}" @endif @endauth
         class="mt-8 rounded-xl border border-border bg-card p-4 sm:p-5">
    <h2 class="text-sm font-semibold">Fill this page in with a real API key</h2>

    <p class="mt-1 text-sm leading-relaxed text-muted-foreground">
        The samples below carry a placeholder key and an example host.
        @auth
            @if ($team)
                Issue one for <span class="font-medium text-foreground">{{ $team->name }}</span> and they are rewritten in
                place — the key is shown once, here and nowhere else.
            @else
                Create a team first, then a project and a key can be issued from here.
            @endif
        @else
            <a href="{{ route('login') }}" class="font-medium text-foreground underline underline-offset-4">Log in</a>
            or
            <a href="{{ route('register') }}" class="font-medium text-foreground underline underline-offset-4">create an account</a>
            to issue one and have them rewritten in place.
        @endauth
    </p>

    @auth
        @if ($team)
            <form data-form class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center">
                @csrf

                @if ($projects !== [])
                    <label class="sr-only" for="docs-api-key-project">Project</label>
                    <select id="docs-api-key-project" name="project" data-project
                            class="h-9 rounded-md border border-border bg-background px-2 text-sm sm:w-48">
                        @foreach ($projects as $project)
                            <option value="{{ $project['slug'] }}">{{ $project['name'] }}</option>
                        @endforeach
                        <option value="">New project…</option>
                    </select>
                @endif

                <label class="sr-only" for="docs-api-key-name">New project name</label>
                <input id="docs-api-key-name" name="name" data-name type="text" value="my-app" maxlength="255"
                       autocomplete="off" spellcheck="false"
                       @if ($projects !== []) hidden @endif
                       class="h-9 min-w-0 flex-1 rounded-md border border-border bg-background px-3 font-mono text-sm">

                <button type="submit" data-submit
                        class="h-9 shrink-0 rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90 disabled:opacity-60">
                    Create API key
                </button>
            </form>

            <p data-error hidden class="mt-3 text-sm text-destructive"></p>

            <div data-result hidden class="mt-4 rounded-lg border border-border bg-background p-3">
                <p class="text-xs text-muted-foreground">
                    Key for <span data-result-project class="font-medium text-foreground"></span>. Shown once — copy it now.
                </p>
                <div class="mt-2 flex items-center gap-2">
                    <code data-result-key class="scrollbar-stream min-w-0 flex-1 overflow-x-auto rounded-md bg-muted px-2 py-1.5 font-mono text-xs whitespace-nowrap"></code>
                    <button type="button" data-copy-key
                            class="h-8 shrink-0 rounded-md border border-border px-2.5 text-xs font-medium transition-colors hover:bg-accent hover:text-accent-foreground">
                        Copy
                    </button>
                </div>
            </div>

            <div data-active hidden class="mt-4 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                <span>This page is showing <code data-active-key class="font-mono text-foreground"></code>.</span>
                <button type="button" data-forget
                        class="rounded-md border border-border px-2 py-1 font-medium transition-colors hover:bg-accent hover:text-accent-foreground">
                    Forget key
                </button>
            </div>
        @endif
    @endauth
</section>

<script nonce="{{ $cspNonce ?? '' }}">
    // The panel sits above the prose it rewrites, so wait for the parse to finish.
    document.addEventListener('DOMContentLoaded', function () {
        const panel = document.querySelector('[data-docs-api-key]');

        // The prose, plus anything else on the page built out of the same
        // placeholders — the copy-as-a-prompt block, for one.
        const targets = document.querySelectorAll('.docs-prose, [data-docs-api-key-target]');

        if (!panel || targets.length === 0) {
            return;
        }

        // The key lives in sessionStorage, not localStorage: it survives a walk
        // through the docs and dies with the tab.
        const STORE = 'bilis.docs.api-key';
        const placeholder = panel.dataset.placeholder;
        const sampleEndpoint = panel.dataset.sampleEndpoint;

        function stored() {
            try {
                const raw = window.sessionStorage.getItem(STORE);

                return raw ? JSON.parse(raw) : null;
            } catch (error) {
                return null;
            }
        }

        function remember(value) {
            try {
                window.sessionStorage.setItem(STORE, JSON.stringify(value));
            } catch (error) {
                // A browser refusing storage costs the rewrite on the next page, nothing more.
            }
        }

        /** Rewrite the placeholder key and example host in the rendered page. */
        function apply(value) {
            targets.forEach((target) => {
                const walker = document.createTreeWalker(target, NodeFilter.SHOW_TEXT);

                for (let node = walker.nextNode(); node !== null; node = walker.nextNode()) {
                    let text = node.nodeValue;

                    if (text.includes(placeholder)) {
                        text = text.split(placeholder).join(value.key);
                    }

                    if (value.endpoint && text.includes(sampleEndpoint)) {
                        text = text.split(sampleEndpoint).join(value.endpoint);
                    }

                    if (text !== node.nodeValue) {
                        node.nodeValue = text;
                    }
                }
            });

            const active = panel.querySelector('[data-active]');

            if (active) {
                panel.querySelector('[data-active-key]').textContent = value.key.slice(0, 12) + '…';
                active.hidden = false;
            }
        }

        const existing = stored();

        if (existing && existing.key) {
            apply(existing);
        }

        const form = panel.querySelector('[data-form]');

        if (!form) {
            return;
        }

        const select = form.querySelector('[data-project]');
        const name = form.querySelector('[data-name]');
        const submit = form.querySelector('[data-submit]');
        const error = panel.querySelector('[data-error]');
        const result = panel.querySelector('[data-result]');

        if (select) {
            select.addEventListener('change', () => {
                name.hidden = select.value !== '';

                if (!name.hidden) {
                    name.focus();
                }
            });
        }

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            error.hidden = true;
            submit.disabled = true;

            try {
                const response = await fetch(panel.dataset.endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        project: select ? select.value : '',
                        name: name.value,
                    }),
                });

                const payload = await response.json();

                if (!response.ok) {
                    throw new Error(payload.message || 'The key could not be created.');
                }

                remember(payload);
                apply(payload);

                panel.querySelector('[data-result-project]').textContent = payload.project.name;
                panel.querySelector('[data-result-key]').textContent = payload.key;
                result.hidden = false;
                form.hidden = true;
            } catch (failure) {
                error.textContent = failure.message;
                error.hidden = false;
            } finally {
                submit.disabled = false;
            }
        });

        panel.querySelector('[data-copy-key]').addEventListener('click', async (event) => {
            const key = panel.querySelector('[data-result-key]').textContent;
            const button = event.currentTarget;

            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(key);
                } else {
                    const area = document.createElement('textarea');
                    area.value = key;
                    document.body.appendChild(area);
                    area.select();
                    document.execCommand('copy');
                    area.remove();
                }

                button.textContent = 'Copied';
            } catch (failure) {
                button.textContent = 'Copy failed';
            }
        });

        panel.querySelector('[data-forget]').addEventListener('click', () => {
            try {
                window.sessionStorage.removeItem(STORE);
            } catch (failure) {
                // Nothing to forget.
            }

            window.location.reload();
        });
    });
</script>
