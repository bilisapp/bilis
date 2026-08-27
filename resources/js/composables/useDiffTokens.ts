import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { Ref } from 'vue';
import { readDiffTokens } from '@/lib/diffs';
import type { DiffTokens } from '@/lib/diffs';
import { resolvedAppearanceFromDocument } from '@/lib/echarts';
import type { ResolvedAppearance } from '@/types';

export type UseDiffTokensReturn = {
    /** The resolved code-rendering CSS custom properties for the current mode. */
    tokens: Ref<DiffTokens>;
    /** The mode the tokens were read in. */
    appearance: Ref<ResolvedAppearance>;
};

/**
 * Track the code-rendering design tokens, re-reading them when the mode flips.
 *
 * The twin of `useChartTokens`, and for the same reason: the tokens are
 * authored per mode, so a cached palette is wrong the moment the appearance
 * toggle is used. `updateAppearance()` and the system-preference listener both
 * work by toggling the `dark` class on the root element, so watching that one
 * attribute covers explicit and `system` modes alike.
 */
export function useDiffTokens(): UseDiffTokensReturn {
    const appearance = ref<ResolvedAppearance>(
        resolvedAppearanceFromDocument(),
    );
    const tokens = ref<DiffTokens>(readDiffTokens());

    let observer: MutationObserver | null = null;

    function refresh() {
        const resolved = resolvedAppearanceFromDocument();

        if (resolved === appearance.value) {
            return;
        }

        appearance.value = resolved;
        tokens.value = readDiffTokens();
    }

    onMounted(() => {
        appearance.value = resolvedAppearanceFromDocument();
        tokens.value = readDiffTokens();

        observer = new MutationObserver(refresh);
        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class'],
        });
    });

    onBeforeUnmount(() => {
        observer?.disconnect();
        observer = null;
    });

    return { tokens, appearance };
}
