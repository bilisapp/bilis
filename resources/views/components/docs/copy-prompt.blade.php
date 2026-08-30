@props([
    'page',
    'placeholder' => \App\Http\Controllers\DocsController::API_KEY_PLACEHOLDER,
    'endpoint' => null,
])

@php
    $endpoint ??= rtrim(url('/'), '/');
@endphp

@php
    /** @var \App\Services\Docs\DocsPage $page */

    /*
     * The prompt points at the raw markdown rather than repeating the guide:
     * the page is already served as text/markdown at a stable URL, so the
     * agent reads the current version instead of a copy that goes stale.
     *
     * The key is the same placeholder the prose carries, so the panel above
     * rewrites it here too once a real key has been issued.
     */
    $prompt = <<<PROMPT
        Set up Bilis — self-hosted log and trace storage — in this project, following its "{$page->title}" guide:

        {$page->markdownUrl()}

        Bilis endpoint: {$endpoint}
        API key: {$placeholder}

        Read the guide first, then make the changes it describes. Keep the API key in the environment rather than in a file that gets committed, and finish by sending one record and confirming it arrives.
        PROMPT;
@endphp

{{--
    Hand the whole setup to a coding agent instead of doing it by hand. The
    prompt is rendered as visible text on purpose: it carries an API key once
    the panel above has filled one in, and nobody should have to copy a secret
    they cannot see.
--}}
<section data-docs-copy-prompt class="mt-4 rounded-xl border border-border bg-card p-4 sm:p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <h2 class="text-sm font-semibold">Or hand it to a coding agent</h2>
            <p class="mt-1 text-sm leading-relaxed text-muted-foreground">
                Copy this prompt into Claude Code — or any agent that can read a URL — and let it do
                the setup against the current version of this page.
            </p>
        </div>

        <button type="button"
                data-copy-prompt-button
                class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-border bg-background px-2.5 py-1.5 text-xs font-medium transition-colors hover:bg-accent hover:text-accent-foreground">
            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="9" y="9" width="12" height="12" rx="2" />
                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
            </svg>
            <span data-copy-prompt-label>Copy as a prompt</span>
        </button>
    </div>

    {{-- data-docs-api-key-target: the API key panel rewrites the placeholder in here as well as in the prose. --}}
    <pre data-copy-prompt-text
         data-docs-api-key-target
         class="scrollbar-stream mt-3 overflow-x-auto rounded-lg bg-background p-3 font-mono text-xs leading-relaxed whitespace-pre-wrap">{{ $prompt }}</pre>
</section>

<script nonce="{{ $cspNonce ?? '' }}">
    (function () {
        const root = document.querySelector('[data-docs-copy-prompt]');

        if (!root) {
            return;
        }

        const button = root.querySelector('[data-copy-prompt-button]');
        const label = root.querySelector('[data-copy-prompt-label]');
        const idle = label.textContent;
        let resetTimer = null;

        function flash(text) {
            label.textContent = text;
            window.clearTimeout(resetTimer);
            resetTimer = window.setTimeout(() => { label.textContent = idle; }, 2000);
        }

        // navigator.clipboard needs a secure context, which a self-hosted
        // instance on plain HTTP over a LAN is not.
        async function write(text) {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);

                return;
            }

            const area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            document.execCommand('copy');
            area.remove();
        }

        button.addEventListener('click', async () => {
            try {
                // Read at click time: the key panel may have rewritten the key since load.
                await write(root.querySelector('[data-copy-prompt-text]').textContent);
                flash('Copied');
            } catch (error) {
                flash('Copy failed');
            }
        });
    })();
</script>
