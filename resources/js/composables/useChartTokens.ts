import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { Ref } from 'vue';
import { readChartTokens, resolvedAppearanceFromDocument } from '@/lib/echarts';
import type { ChartTokens } from '@/lib/echarts';
import type { ResolvedAppearance } from '@/types';

export type UseChartTokensReturn = {
    /** The resolved chart CSS custom properties for the current mode. */
    tokens: Ref<ChartTokens>;
    /** The mode the tokens were read in. */
    appearance: Ref<ResolvedAppearance>;
};

/**
 * Track the chart design tokens, re-reading them whenever the appearance flips.
 *
 * `useAppearance().updateAppearance()` toggles the `dark` class on the root
 * element — and so does the system-preference listener — so watching that
 * class covers explicit and `system` mode alike. Browser only: on the server
 * this hands back the neutral fallback tokens and never observes anything.
 */
export function useChartTokens(): UseChartTokensReturn {
    const appearance = ref<ResolvedAppearance>(
        resolvedAppearanceFromDocument(),
    );
    const tokens = ref<ChartTokens>(readChartTokens());

    let observer: MutationObserver | null = null;

    function refresh() {
        const resolved = resolvedAppearanceFromDocument();

        if (resolved === appearance.value) {
            return;
        }

        appearance.value = resolved;
        tokens.value = readChartTokens();
    }

    onMounted(() => {
        appearance.value = resolvedAppearanceFromDocument();
        tokens.value = readChartTokens();

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
