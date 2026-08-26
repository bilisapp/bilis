import { onBeforeUnmount, onMounted, watch } from 'vue';
import { useTailStatus } from '@/composables/useTailStatus';
import { SEVERITY_CSS_VARIABLE } from '@/lib/logs';

/**
 * Report tail activity through the browser tab.
 *
 * The whole reason a reader leaves a tail open during a deploy is to not have
 * to watch it, so the tab does the watching: the title carries a count of the
 * lines that arrived while they were away, and the favicon takes a dot in the
 * loudest severity among them. Both reset the moment the tab is looked at
 * again — an unread count that survives being read is a lie.
 */
const FAVICON_SELECTOR = 'link[rel="icon"][type="image/svg+xml"]';

export function useTailTabBadge(): void {
    const { tailing, unseen, unseenPeak, clearUnseen } = useTailStatus();

    let baseFavicon: string | null = null;
    let faviconLink: HTMLLinkElement | null = null;
    let markImage: HTMLImageElement | null = null;

    /**
     * Draw the mark with a severity dot in its corner.
     *
     * Returns null whenever anything about the drawing fails — a missing
     * favicon, a canvas the browser will not give us, an image that never
     * loads. The title badge is the part that matters; the dot is a bonus and
     * must never take the page down with it.
     */
    const badgedFavicon = (color: string): string | null => {
        if (!markImage || !markImage.complete || markImage.naturalWidth === 0) {
            return null;
        }

        try {
            const size = 64;
            const canvas = document.createElement('canvas');
            canvas.width = size;
            canvas.height = size;

            const context = canvas.getContext('2d');

            if (!context) {
                return null;
            }

            context.drawImage(markImage, 0, 0, size, size);

            // Punch a hole first so the dot reads on any mark colour.
            context.globalCompositeOperation = 'destination-out';
            context.beginPath();
            context.arc(size - 19, size - 19, 21, 0, Math.PI * 2);
            context.fill();

            context.globalCompositeOperation = 'source-over';
            context.fillStyle = color;
            context.beginPath();
            context.arc(size - 19, size - 19, 17, 0, Math.PI * 2);
            context.fill();

            return canvas.toDataURL('image/png');
        } catch {
            return null;
        }
    };

    const resolvedSeverityColor = (): string => {
        if (!unseenPeak.value) {
            return '#888888';
        }

        const value = getComputedStyle(document.documentElement)
            .getPropertyValue(SEVERITY_CSS_VARIABLE[unseenPeak.value])
            .trim();

        return value || '#888888';
    };

    /**
     * The page title is Inertia's to set, and it sets it after this mounts —
     * so the base is read off the live title each time, with any badge we
     * previously added stripped back out, rather than snapshotted once.
     */
    const baseTitle = () => document.title.replace(/^\(\d+\+?\)\s*/, '');

    const render = () => {
        if (unseen.value === 0) {
            document.title = baseTitle();

            if (faviconLink && baseFavicon !== null) {
                faviconLink.href = baseFavicon;
            }

            return;
        }

        const count = unseen.value > 99 ? '99+' : String(unseen.value);

        document.title = `(${count}) ${baseTitle()}`;

        if (!faviconLink) {
            return;
        }

        const badged = badgedFavicon(resolvedSeverityColor());

        if (badged) {
            faviconLink.href = badged;
        }
    };

    const onVisibility = () => {
        if (document.visibilityState === 'visible') {
            clearUnseen();
        }
    };

    onMounted(() => {
        faviconLink = document.querySelector<HTMLLinkElement>(FAVICON_SELECTOR);

        if (faviconLink) {
            baseFavicon = faviconLink.href;
            markImage = new Image();
            markImage.src = baseFavicon;
        }

        document.addEventListener('visibilitychange', onVisibility);
    });

    onBeforeUnmount(() => {
        document.removeEventListener('visibilitychange', onVisibility);
        clearUnseen();
        document.title = baseTitle();

        if (faviconLink && baseFavicon !== null) {
            faviconLink.href = baseFavicon;
        }
    });

    watch([unseen, unseenPeak], render);

    // A tail that is switched off stops reporting, even in the background.
    watch(tailing, (enabled) => {
        if (!enabled) {
            clearUnseen();
        }
    });
}
