<script setup lang="ts">
import { CircleCheck, CircleDot, EyeOff, RotateCcw } from '@lucide/vue';
import { computed } from 'vue';
import { ISSUE_STATUS_LABEL } from '@/lib/issues';
import { cn } from '@/lib/utils';
import type { IssueStatus } from '@/types';

/**
 * Where an issue stands, as a quiet pill.
 *
 * Achromatic like the rest of the chrome, with one exception that is data,
 * not decoration: a regressed issue takes the severity error token, because
 * "it broke again after we said it was fixed" is the one status that should
 * be seen before it is read.
 */
const props = defineProps<{
    status: IssueStatus;
}>();

const icon = computed(
    () =>
        ({
            unresolved: CircleDot,
            regressed: RotateCcw,
            resolved: CircleCheck,
            ignored: EyeOff,
        })[props.status],
);
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex shrink-0 items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium',
                status === 'regressed'
                    ? 'border-severity-error/40 text-severity-error'
                    : 'text-muted-foreground',
            )
        "
        :data-test="`issue-status-${status}`"
    >
        <component :is="icon" class="size-3" aria-hidden="true" />
        {{ ISSUE_STATUS_LABEL[status] }}
    </span>
</template>
