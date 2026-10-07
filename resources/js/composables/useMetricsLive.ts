import type { VisitOptions } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, readonly, ref, watch } from 'vue';
import type { Ref } from 'vue';
import { liveRefreshMs } from '@/lib/metrics';

/**
 * Whether the metric charts are live. Module scope, so it survives switching
 * between the Hosts tab and the explorer: both are one page to the reader.
 */
const live = ref(false);

export type UseMetricsLiveOptions = {
    /** Live only means something for a window that ends at now. */
    available: () => boolean;
    /** The charts' bucket width; the refresh cadence follows it. */
    intervalSeconds: () => number;
    /**
     * Ask the server again: a partial reload of the chart props over the same
     * relative window, re-resolved against the clock. Spread `options` into
     * the visit — it carries the async flag and the bookkeeping callbacks.
     */
    refresh: (options: Partial<VisitOptions>) => void;
};

export type UseMetricsLiveReturn = {
    live: Ref<boolean>;
    /** True while a refresh is in flight. */
    refreshing: Readonly<Ref<boolean>>;
};

/**
 * Keep the metric charts current by polling — not SSE, not long-polling.
 *
 * Ingest has no push step (inserts go straight to ClickHouse), so a held
 * connection would only poll ClickHouse itself while pinning an Octane worker;
 * points arrive about once a minute anyway. Each tick re-reads the whole
 * window rather than appending: cumulative deltas, the 500-series cap and the
 * ten-group cut can all shift as the window slides.
 *
 * One refresh at a time, the next scheduled when it finishes. A hidden tab is
 * not refreshed; it catches up the moment it is shown. A navigation the reader
 * started cancels an in-flight refresh — its response would carry the old
 * filters — and restarts the clock once it lands.
 */
export function useMetricsLive(
    options: UseMetricsLiveOptions,
): UseMetricsLiveReturn {
    const refreshing = ref(false);

    let timer: ReturnType<typeof setTimeout> | null = null;
    let cancelInFlight: (() => void) | null = null;
    let navigating = false;
    let removeListeners: (() => void)[] = [];

    const clear = () => {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    };

    const active = () => live.value && options.available();

    const schedule = () => {
        clear();

        if (active()) {
            timer = setTimeout(tick, liveRefreshMs(options.intervalSeconds()));
        }
    };

    function tick() {
        timer = null;

        if (!active() || refreshing.value || navigating) {
            return;
        }

        // Caught up by onVisibility when the tab is shown again.
        if (document.visibilityState === 'hidden') {
            return;
        }

        refreshing.value = true;

        options.refresh({
            async: true,
            showProgress: false,
            preserveScroll: true,
            preserveState: true,
            replace: true,
            onCancelToken: (token) => {
                cancelInFlight = token.cancel;
            },
            onFinish: () => {
                refreshing.value = false;
                cancelInFlight = null;
                schedule();
            },
        });
    }

    const onVisibility = () => {
        if (document.visibilityState === 'visible' && timer === null) {
            tick();
        }
    };

    watch(live, (enabled) => {
        if (enabled) {
            tick();
        } else {
            clear();
            cancelInFlight?.();
        }
    });

    watch(
        () => [options.available(), options.intervalSeconds()],
        ([available]) => {
            if (!available) {
                live.value = false;
            }

            if (!refreshing.value) {
                schedule();
            }
        },
    );

    onMounted(() => {
        document.addEventListener('visibilitychange', onVisibility);

        removeListeners = [
            router.on('start', (event) => {
                if (!event.detail.visit.async) {
                    navigating = true;
                    clear();
                    cancelInFlight?.();
                }
            }),
            router.on('finish', (event) => {
                if (!event.detail.visit.async) {
                    navigating = false;
                    schedule();
                }
            }),
        ];

        if (!options.available()) {
            live.value = false;
        }

        schedule();
    });

    onBeforeUnmount(() => {
        document.removeEventListener('visibilitychange', onVisibility);
        removeListeners.forEach((remove) => remove());
        clear();
        cancelInFlight?.();
    });

    return { live, refreshing: readonly(refreshing) };
}
