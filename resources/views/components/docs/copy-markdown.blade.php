@props(['url'])

{{--
    Copy the page as markdown, for pasting into an editor or a model. The raw
    file is one fetch away at the same URL the "View raw" link points at, so
    nothing has to be duplicated into the HTML.
--}}
<div class="flex shrink-0 items-center gap-1" data-copy-markdown data-url="{{ $url }}">
    <button type="button"
            data-copy-markdown-button
            class="inline-flex items-center gap-1.5 rounded-md border border-border bg-card px-2.5 py-1.5 text-xs font-medium transition-colors hover:bg-accent hover:text-accent-foreground">
        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="9" y="9" width="12" height="12" rx="2" />
            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
        </svg>
        <span data-copy-markdown-label>Copy as Markdown</span>
    </button>

    <a href="{{ $url }}"
       class="rounded-md px-2.5 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground">
        View raw
    </a>
</div>

<script nonce="{{ $cspNonce ?? '' }}">
    (function () {
        const root = document.querySelector('[data-copy-markdown]');

        if (!root) {
            return;
        }

        const button = root.querySelector('[data-copy-markdown-button]');
        const label = root.querySelector('[data-copy-markdown-label]');
        const idle = label.textContent;
        let resetTimer = null;

        function flash(text) {
            label.textContent = text;
            window.clearTimeout(resetTimer);
            resetTimer = window.setTimeout(() => { label.textContent = idle; }, 2000);
        }

        // navigator.clipboard needs a secure context; a self-hosted instance on
        // plain HTTP over a LAN is not one, so keep the old path as a fallback.
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
                const response = await fetch(root.dataset.url, { headers: { Accept: 'text/markdown' } });

                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                await write(await response.text());
                flash('Copied');
            } catch (error) {
                flash('Copy failed');
            }
        });
    })();
</script>
