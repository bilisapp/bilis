<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

defineProps<{ open: boolean }>();

defineEmits<{ (event: 'update:open', value: boolean): void }>();

/**
 * Every shortcut has a pointer equivalent — this sheet is a shortcut to the
 * mouse, never the only route to anything.
 */
const SHORTCUTS: { keys: string[]; label: string }[] = [
    { keys: ['j'], label: 'Next line' },
    { keys: ['k'], label: 'Previous line' },
    { keys: ['g'], label: 'Jump to the newest line' },
    { keys: ['G'], label: 'Jump to the oldest loaded line' },
    { keys: ['o', 'Enter'], label: 'Expand or collapse the line' },
    { keys: ['y'], label: 'Copy the line' },
    { keys: ['/'], label: 'Search log bodies' },
    { keys: ['Esc'], label: 'Collapse, then leave the line' },
    { keys: ['?'], label: 'This sheet' },
];
</script>

<template>
    <Dialog :open="open" @update:open="$emit('update:open', $event)">
        <DialogContent class="sm:max-w-md" data-test="logs-shortcuts">
            <DialogHeader>
                <DialogTitle>Keyboard</DialogTitle>
                <DialogDescription>
                    The stream answers to the same keys as
                    <span class="font-mono">less</span>. Nothing here is the
                    only way to do anything.
                </DialogDescription>
            </DialogHeader>

            <dl class="grid gap-1">
                <div
                    v-for="shortcut in SHORTCUTS"
                    :key="shortcut.label"
                    class="flex items-center justify-between gap-4 rounded-md px-2 py-1.5 text-sm odd:bg-muted/40"
                >
                    <dt class="text-muted-foreground">
                        {{ shortcut.label }}
                    </dt>
                    <dd class="flex shrink-0 items-center gap-1">
                        <template
                            v-for="(key, index) in shortcut.keys"
                            :key="key"
                        >
                            <span
                                v-if="index > 0"
                                class="text-xs text-muted-foreground"
                            >
                                or
                            </span>
                            <kbd
                                class="inline-flex h-6 min-w-6 items-center justify-center rounded border border-input bg-background px-1.5 font-mono text-xs"
                            >
                                {{ key }}
                            </kbd>
                        </template>
                    </dd>
                </div>
            </dl>
        </DialogContent>
    </Dialog>
</template>
