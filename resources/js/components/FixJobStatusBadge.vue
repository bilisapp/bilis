<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { FixJobStatus } from '@/types';

/**
 * Where one autofix attempt has got to.
 *
 * The badge itself stays achromatic — an outline on the neutral ladder, like
 * every other piece of chrome. The only hue is the dot, and it is borrowed
 * from the severity ramp rather than invented: teal for an outcome that
 * landed, crimson for one that did not, nothing at all while the job is still
 * deciding. A status is not severity, but it is *data about an error*, which
 * is the one thing this palette is spent on.
 */
const props = defineProps<{
    status: FixJobStatus;
    label: string;
    /** Class applied to the badge, for callers that need to space it. */
    class?: string;
}>();

const DOT_CLASSES: Record<FixJobStatus, string> = {
    pending: 'bg-muted-foreground',
    dispatched: 'bg-muted-foreground',
    running: 'bg-severity-info',
    validating: 'bg-severity-info',
    pr_opened: 'bg-severity-debug',
    merged: 'bg-severity-debug',
    no_change: 'bg-severity-debug',
    rejected: 'bg-severity-warn',
    failed: 'bg-severity-error',
    timeout: 'bg-severity-error',
    cancelled: 'bg-muted-foreground',
};

const PULSING: FixJobStatus[] = ['running', 'validating'];

const dotClass = computed(() => DOT_CLASSES[props.status]);
const pulses = computed(() => PULSING.includes(props.status));
</script>

<template>
    <Badge
        variant="outline"
        :class="cn('gap-1.5 font-medium', props.class)"
        :data-status="status"
        data-test="fix-job-status"
    >
        <span class="relative flex size-1.5">
            <span
                v-if="pulses"
                :class="
                    cn(
                        'absolute inline-flex size-1.5 animate-ping rounded-full opacity-70 motion-reduce:hidden',
                        dotClass,
                    )
                "
            />
            <span
                :class="
                    cn('relative inline-flex size-1.5 rounded-full', dotClass)
                "
            />
        </span>
        {{ label }}
    </Badge>
</template>
