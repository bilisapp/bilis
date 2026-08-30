<script setup lang="ts">
import { Check, ChevronDown, ChevronRight, Copy, Search } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { groupAttributes } from '@/lib/attributes';
import type { SpanAttribute } from '@/lib/attributes';
import { SPAN_STATUS_TEXT_CLASS } from '@/lib/traces';
import { cn } from '@/lib/utils';

const props = defineProps<{
    attributes: Record<string, string>;
    /**
     * Resets the filter and the folds. A different span is a different set of
     * attributes, and a filter left over from the last one reads as an empty
     * span rather than as a filter.
     */
    resetKey?: string;
    /** Event attributes are a handful, already in context: no groups, no filter. */
    flat?: boolean;
}>();

/** Below this a filter is slower than reading the list it filters. */
const FILTER_THRESHOLD = 12;

const query = ref('');
const expanded = ref<Set<string>>(new Set());
const revealed = ref<Set<string>>(new Set());

watch(
    () => props.resetKey,
    () => {
        query.value = '';
        expanded.value = new Set();
        revealed.value = new Set();
    },
);

const total = computed(() => Object.keys(props.attributes).length);
const showFilter = computed(
    () => !props.flat && total.value > FILTER_THRESHOLD,
);

const groups = computed(() =>
    props.flat
        ? [
              {
                  id: 'all',
                  title: '',
                  collapsedByDefault: false,
                  attributes: Object.entries(props.attributes).map(
                      ([key, value]) => ({
                          ...groupAttributes({ [key]: value })[0].attributes[0],
                      }),
                  ),
              },
          ]
        : groupAttributes(props.attributes, query.value),
);

/**
 * A group is open unless it says otherwise — and a filter opens everything,
 * because a hit hidden inside a folded group is indistinguishable from no hit.
 */
function isOpen(group: { id: string; collapsedByDefault: boolean }): boolean {
    if (query.value.trim() !== '') {
        return true;
    }

    // `expanded` holds the groups whose fold has been *toggled*, not the ones
    // that are open — so a group is open when its toggle state agrees with the
    // default it started from.
    return expanded.value.has(group.id) === group.collapsedByDefault;
}

function toggle(id: string): void {
    const next = new Set(expanded.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    expanded.value = next;
}

/** Long code blocks are clamped; this is the per-attribute release. */
function reveal(key: string): void {
    const next = new Set(revealed.value);

    next.add(key);
    revealed.value = next;
}

/**
 * A short value sits beside its key; a long one gets its own line.
 *
 * Stacking every pair is safe and doubles the height of the panel — thirteen
 * model attributes become twenty-six lines, and the reader scrolls past the
 * thing they came for. Stacking only what cannot fit keeps the common case one
 * line per fact while `user.id` and a shell command still get the full width.
 */
function isInline(attribute: SpanAttribute): boolean {
    return attribute.kind !== 'code' && attribute.display.length <= 32;
}

function isClamped(attribute: SpanAttribute): boolean {
    return (
        attribute.kind === 'code' &&
        !revealed.value.has(attribute.key) &&
        (attribute.value.length > 220 || attribute.value.split('\n').length > 6)
    );
}

const { copy, copied } = useClipboard({ copiedDuring: 1_500, legacy: true });
const copiedKey = ref('');

function copyValue(attribute: SpanAttribute): void {
    copiedKey.value = attribute.key;
    copy(attribute.value);
}
</script>

<template>
    <div class="flex flex-col gap-3" data-test="span-attributes">
        <!--
          The filter is an input, not a control surface: it sits at the top of
          the list it filters and stays quiet until used.
        -->
        <div v-if="showFilter" class="relative">
            <Search
                class="pointer-events-none absolute top-1/2 left-2 size-3.5 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <input
                v-model="query"
                type="search"
                placeholder="Filter attributes"
                aria-label="Filter attributes"
                data-test="span-attributes-filter"
                class="h-8 w-full rounded-md border border-input bg-transparent pr-2 pl-7 text-xs placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
            />
        </div>

        <p
            v-if="groups.length === 0"
            class="text-xs text-muted-foreground"
            data-test="span-attributes-empty"
        >
            {{
                total === 0
                    ? 'This span carries no attributes.'
                    : `No attribute matches “${query}”.`
            }}
        </p>

        <div
            v-for="group in groups"
            :key="group.id"
            class="flex flex-col gap-1.5"
        >
            <!--
              Not the uppercase group label: that weight belongs to the sidebar
              and nowhere else (DESIGN.md). A label plus a count, on a rule that
              runs to the edge, is enough to separate two dozen rows into parts.
            -->
            <button
                v-if="!flat"
                type="button"
                class="group -mx-1 flex items-center gap-1.5 rounded px-1 py-0.5 text-left transition-colors hover:bg-accent/50 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                :aria-expanded="isOpen(group)"
                :data-test="`span-attribute-group-${group.id}`"
                @click="toggle(group.id)"
            >
                <component
                    :is="isOpen(group) ? ChevronDown : ChevronRight"
                    class="size-3 shrink-0 text-muted-foreground"
                />
                <span class="text-xs font-medium">{{ group.title }}</span>
                <span class="text-xs text-muted-foreground tabular-nums">
                    {{ group.attributes.length }}
                </span>
                <span class="ml-1 h-px flex-1 bg-border" aria-hidden="true" />
            </button>

            <dl v-if="isOpen(group)" class="flex flex-col gap-2.5">
                <div
                    v-for="attribute in group.attributes"
                    :key="attribute.key"
                    :class="
                        cn(
                            'group/row gap-x-3 gap-y-0.5',
                            isInline(attribute)
                                ? 'flex items-baseline justify-between'
                                : 'flex flex-col',
                        )
                    "
                >
                    <dt class="flex min-w-0 items-baseline gap-1.5">
                        <span
                            :class="
                                cn(
                                    'min-w-0 font-mono text-xs',
                                    isInline(attribute)
                                        ? 'truncate'
                                        : 'break-all',
                                )
                            "
                        >
                            <!--
                              The namespace repeats down the whole group, so it
                              recedes; the last segment is what a reader is
                              actually scanning for and keeps the weight.
                            -->
                            <span
                                v-if="attribute.prefix"
                                class="text-muted-foreground/60"
                                >{{ attribute.prefix }}</span
                            ><span class="text-muted-foreground">{{
                                attribute.name
                            }}</span>
                        </span>

                        <button
                            type="button"
                            class="ml-auto inline-flex size-4 shrink-0 items-center justify-center rounded text-muted-foreground opacity-0 transition-opacity group-hover/row:opacity-100 hover:text-foreground focus-visible:opacity-100 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                            :aria-label="`Copy ${attribute.key}`"
                            :title="
                                copied && copiedKey === attribute.key
                                    ? 'Copied'
                                    : 'Copy value'
                            "
                            @click="copyValue(attribute)"
                        >
                            <component
                                :is="
                                    copied && copiedKey === attribute.key
                                        ? Check
                                        : Copy
                                "
                                class="size-3"
                            />
                        </button>
                    </dt>

                    <dd
                        :class="
                            isInline(attribute)
                                ? 'min-w-0 shrink-0 text-right'
                                : ''
                        "
                    >
                        <!--
                          A command, a query or a prompt is written in lines and
                          has to be read in lines. Everything else is one value
                          and gets one line's worth of treatment.
                        -->
                        <div v-if="attribute.kind === 'code'" class="relative">
                            <pre
                                :class="
                                    cn(
                                        'overflow-x-auto rounded-md border bg-muted/40 px-2 py-1.5 font-mono text-xs whitespace-pre-wrap',
                                        isClamped(attribute) &&
                                            'max-h-24 overflow-hidden',
                                    )
                                "
                            ><code>{{ attribute.display }}</code></pre>

                            <button
                                v-if="isClamped(attribute)"
                                type="button"
                                class="absolute inset-x-px bottom-px flex items-end justify-center rounded-b-md bg-gradient-to-t from-card via-card/90 to-transparent pt-6 pb-1 text-xs text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none"
                                @click="reveal(attribute.key)"
                            >
                                Show all
                            </button>
                        </div>

                        <span
                            v-else-if="
                                attribute.kind === 'boolean' ||
                                attribute.kind === 'status'
                            "
                            :class="
                                cn(
                                    'inline-block rounded border px-1.5 font-mono text-xs',
                                    attribute.failed &&
                                        SPAN_STATUS_TEXT_CLASS.Error,
                                )
                            "
                        >
                            {{ attribute.display }}
                        </span>

                        <a
                            v-else-if="attribute.kind === 'url'"
                            :href="attribute.value"
                            rel="noreferrer noopener"
                            target="_blank"
                            class="block truncate font-mono text-xs underline underline-offset-2"
                            :title="attribute.value"
                        >
                            {{ attribute.display }}
                        </a>

                        <span
                            v-else
                            :class="
                                cn(
                                    'block font-mono text-xs break-all',
                                    // Any column of digits a reader scans
                                    // vertically is tabular (DESIGN.md).
                                    (attribute.kind === 'count' ||
                                        attribute.kind === 'duration') &&
                                        'tabular-nums',
                                    attribute.kind === 'identifier' &&
                                        'text-muted-foreground',
                                    attribute.failed &&
                                        SPAN_STATUS_TEXT_CLASS.Error,
                                )
                            "
                        >
                            {{ attribute.display }}
                        </span>
                    </dd>
                </div>
            </dl>
        </div>
    </div>
</template>
