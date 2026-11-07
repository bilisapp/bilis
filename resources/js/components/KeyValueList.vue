<script setup lang="ts">
/**
 * Attribute pairs as hairline-ruled rows: the key quiet and truncated in a
 * fixed lane, the value in mono taking the rest and breaking anywhere.
 *
 * `wide` gives keys a 12rem lane for long dotted names; `preserveWhitespace`
 * keeps the line breaks a value was sent with (JSON, multi-line strings).
 */
withDefaults(
    defineProps<{
        entries: [string, string][];
        wide?: boolean;
        preserveWhitespace?: boolean;
    }>(),
    { wide: false, preserveWhitespace: false },
);
</script>

<template>
    <dl class="flex flex-col text-xs">
        <div
            v-for="[key, value] in entries"
            :key="key"
            :class="[
                'grid gap-3 border-b border-border/60 py-2',
                wide
                    ? 'grid-cols-[12rem_minmax(0,1fr)]'
                    : 'grid-cols-[9rem_minmax(0,1fr)]',
            ]"
        >
            <dt class="truncate text-muted-foreground" :title="key">
                {{ key }}
            </dt>
            <dd
                :class="[
                    'font-mono break-all text-foreground/80',
                    preserveWhitespace && 'whitespace-pre-wrap',
                ]"
            >
                {{ value }}
            </dd>
        </div>
    </dl>
</template>
