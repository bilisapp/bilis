<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, shallowRef, watch } from 'vue';
import { useDiffTokens } from '@/composables/useDiffTokens';
import { diffChromeVariables, ensureDiffTheme, loadDiffs } from '@/lib/diffs';
import type { DiffsModule } from '@/lib/diffs';

/**
 * The one place Bilis renders code.
 *
 * Wraps the vanilla `@pierre/diffs` components — `FileDiff` for a unified
 * patch, `File` for a single source excerpt — the way `ChartCanvas` wraps
 * ECharts: the library is loaded on demand, mounted into a ref'd element,
 * themed from the app's CSS tokens for both modes, and disposed on unmount.
 *
 * Nothing else in the app hand-rolls diff markup, and no other diff library is
 * used. See `.ai/rules/js.md`.
 */
const props = withDefaults(
    defineProps<{
        /** A unified diff. Every file it names is rendered, in order. */
        patch?: string | null;
        /** A single file's source, rendered instead of a patch. */
        code?: string | null;
        /** The filename the language is inferred from. */
        filename?: string;
        /** Side by side, or one column. */
        diffStyle?: 'unified' | 'split';
        /** Long code scrolls inside its own box rather than the page. */
        maxHeight?: string;
        /** Hide the per-file header strip. */
        hideHeader?: boolean;
    }>(),
    {
        patch: null,
        code: null,
        filename: 'file.txt',
        diffStyle: 'unified',
        maxHeight: '32rem',
        hideHeader: false,
    },
);

/**
 * The only thing this wrapper ever asks of a rendered instance is that it can
 * be torn down. Naming that rather than the concrete classes keeps the generic
 * annotation parameter (`FileDiff<LAnnotation>`) out of a component that never
 * uses annotations.
 */
type DisposableCode = { cleanUp(recycle?: boolean): void };
type CodeOptions = Record<string, unknown>;

const container = shallowRef<HTMLDivElement | null>(null);
const instances = shallowRef<DisposableCode[]>([]);
const failed = shallowRef(false);
const ready = shallowRef(false);

const { tokens, appearance } = useDiffTokens();

/**
 * The diff's own chrome, handed over as inherited custom properties so the
 * gutter, context rows and add/remove tints match the surrounding card in
 * whichever mode the reader is in. The library reads each of these as the
 * fallback arm of a `var()`, which is what lets an outside value win.
 */
const chromeStyle = computed(() => ({
    ...diffChromeVariables(tokens.value),
    colorScheme: appearance.value,
    maxHeight: props.maxHeight,
}));

/**
 * A render is identified by its content plus the mode it was drawn in, so a
 * repaint happens exactly when one of those changed.
 */
const renderKey = computed(() =>
    [
        props.patch ?? '',
        props.code ?? '',
        props.filename,
        props.diffStyle,
        appearance.value,
    ].join('\u0000'),
);

function disposeInstances() {
    for (const instance of instances.value) {
        instance.cleanUp();
    }

    instances.value = [];
    container.value?.replaceChildren();
}

function renderPatch(
    diffs: DiffsModule,
    target: HTMLElement,
    options: CodeOptions,
) {
    const created: DisposableCode[] = [];

    for (const patch of diffs.parsePatchFiles(props.patch ?? '')) {
        for (const fileDiff of patch.files) {
            const instance = new diffs.FileDiff({
                ...options,
                diffStyle: props.diffStyle,
            });

            // `containerWrapper` is the element to render INTO; the library
            // creates its own `diffs-container` inside it. Passing our div as
            // `fileContainer` instead would make it *be* the container — a
            // plain element with no shadow root, so none of the library's
            // layout CSS applies and the gutter stacks above the code.
            instance.render({ fileDiff, containerWrapper: target });
            created.push(instance);
        }
    }

    instances.value = created;
}

function renderFile(
    diffs: DiffsModule,
    target: HTMLElement,
    options: CodeOptions,
) {
    const instance = new diffs.File(options);

    // See renderPatch for why this is `containerWrapper`, not `fileContainer`.
    instance.render({
        file: { name: props.filename, contents: props.code ?? '' },
        containerWrapper: target,
    });

    instances.value = [instance];
}

async function render() {
    if (!container.value) {
        return;
    }

    const key = renderKey.value;

    try {
        const diffs = await loadDiffs();
        const theme = await ensureDiffTheme(appearance.value, tokens.value);
        const target = container.value;

        // The element may have been unmounted or re-keyed while the library
        // was in flight; a late render would paint a stale diff.
        if (!target || renderKey.value !== key) {
            return;
        }

        disposeInstances();
        failed.value = false;

        const options: CodeOptions = {
            theme,
            themeType: appearance.value,
            disableFileHeader: props.hideHeader,
            overflow: 'scroll',
        };

        if (props.patch) {
            renderPatch(diffs, target, options);
        } else if (typeof props.code === 'string') {
            renderFile(diffs, target, options);
        }
    } catch (error) {
        console.error('CodeCanvas: rendering code failed', error);
        failed.value = true;
    }

    ready.value = true;
}

onMounted(() => {
    void render();
});

onBeforeUnmount(() => {
    disposeInstances();
});

// The Shiki theme is resolved into the instance at render time, so an
// appearance flip has to repaint — exactly like ChartCanvas.
watch(renderKey, () => {
    void render();
});
</script>

<template>
    <div
        class="overflow-auto rounded-lg border bg-card"
        :style="chromeStyle"
        data-test="code-canvas"
    >
        <p
            v-if="failed"
            class="p-4 text-sm text-muted-foreground"
            data-test="code-canvas-error"
        >
            This code could not be rendered.
        </p>

        <div
            v-show="!failed"
            ref="container"
            :data-ready="ready ? 'true' : 'false'"
        />
    </div>
</template>
