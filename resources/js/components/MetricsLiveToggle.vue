<script setup lang="ts">
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/**
 * The metric pages' Live switch, drawn like the trace list's: a dot that
 * pings while on. Offered only for a window that ends at now — a custom range
 * has nothing new to show — and says why when it is off for that reason.
 */
const props = withDefaults(
    defineProps<{
        live: boolean;
        /** False for a custom window: there is nothing to follow. */
        available?: boolean;
        /** A refresh is in flight. */
        refreshing?: boolean;
    }>(),
    { available: true, refreshing: false },
);

const emit = defineEmits<{
    (event: 'update:live', value: boolean): void;
}>();

const title = computed(() =>
    props.available
        ? props.live
            ? 'Charts refresh as new points arrive. Click to stop.'
            : 'Refresh the charts as new points arrive.'
        : 'Live needs a window that ends now — pick a preset.',
);
</script>

<template>
    <Button
        type="button"
        size="sm"
        data-test="metrics-live"
        :variant="live ? 'default' : 'outline'"
        :aria-pressed="live"
        :disabled="!available"
        :title="title"
        @click="emit('update:live', !live)"
    >
        <span class="relative flex size-2 items-center justify-center">
            <span
                v-if="live"
                class="absolute inline-flex size-2 animate-ping rounded-full bg-current opacity-60 motion-reduce:hidden"
            />
            <span
                :class="
                    cn(
                        'relative inline-flex size-2 rounded-full',
                        live
                            ? 'bg-current'
                            : 'border border-current opacity-60',
                        refreshing && 'opacity-100',
                    )
                "
            />
        </span>
        Live
    </Button>
</template>
