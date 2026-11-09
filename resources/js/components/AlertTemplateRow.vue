<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AlertStateBadge from '@/components/AlertStateBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    ALERT_SEVERITY_LABEL,
    ALERT_SOURCE_LABEL,
    describeQuery,
    formatCondition,
    formatSeconds,
} from '@/lib/alerts';
import { edit as alertsEdit } from '@/routes/alerts';
import type { AlertTemplateSummary } from '@/types';

/**
 * One entry of Bilis' alert catalogue on the Alerts page's Templates tab:
 * what it watches, why it exists, and where it stands for this team.
 *
 * The left column reads like an AlertRuleRow — name, reason, condition in the
 * product's words — so a template and the rule it becomes look alike. The
 * right column is the status and the one action: "Turn on" for a template not
 * set up yet (installed now, without waiting for its data) or one that was
 * turned off; "Edit" once it is on.
 *
 * The action emits; the page owns the request.
 */
const props = withDefaults(
    defineProps<{
        template: AlertTemplateSummary;
        teamSlug: string;
        /** While this row's request is in flight. */
        busy?: boolean;
    }>(),
    { busy: false },
);

const emit = defineEmits<{ activate: [] }>();

const REQUIRES_LABEL: Record<AlertTemplateSummary['requires'], string> = {
    logs: 'logs',
    traces: 'traces',
    hosts: 'host metrics',
};

const condition = computed(() =>
    formatCondition({
        source: props.template.source,
        query: props.template.query,
        condition: props.template.condition,
        threshold: props.template.threshold,
        forSeconds: props.template.forSeconds,
    }),
);

const measured = computed(
    () =>
        `${describeQuery(props.template.source, props.template.query)} · ${formatSeconds(props.template.windowSeconds)} window`,
);

const editHref = computed(() =>
    props.template.ruleId !== null
        ? alertsEdit([props.teamSlug, props.template.ruleId]).url
        : null,
);
</script>

<template>
    <div
        class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-6 gap-y-1 border-b border-border/70 px-4 py-4 transition-colors hover:bg-muted/50 md:grid-cols-[minmax(0,1fr)_7.5rem_7rem] md:px-10"
        :data-test="`alert-template-${template.key}`"
    >
        <div class="flex min-w-0 flex-col gap-1">
            <div class="flex min-w-0 items-center gap-2">
                <span
                    class="truncate text-sm font-semibold text-foreground"
                    :title="template.name"
                    >{{ template.name }}</span
                >
                <span class="shrink-0 text-xs text-muted-foreground">
                    {{ ALERT_SOURCE_LABEL[template.source] }} ·
                    {{ ALERT_SEVERITY_LABEL[template.severity] }}
                </span>
                <Badge
                    v-if="template.customized"
                    variant="outline"
                    class="font-normal text-muted-foreground"
                    title="Edited since Bilis set it up, so Bilis no longer updates it."
                    >Edited</Badge
                >
            </div>

            <p class="line-clamp-2 text-xs text-muted-foreground">
                {{ template.why }}
            </p>

            <p
                class="truncate font-mono text-xs text-foreground/80"
                :title="`${condition} — ${measured}`"
            >
                <span class="font-medium text-foreground">{{ condition }}</span>
                <span class="text-muted-foreground"> · {{ measured }}</span>
            </p>

            <p
                v-if="template.status === 'available'"
                class="text-xs text-muted-foreground"
            >
                Bilis sets this up by itself once it sees
                {{ REQUIRES_LABEL[template.requires] }} from your team.
            </p>
        </div>

        <div class="hidden md:block">
            <AlertStateBadge
                v-if="template.status === 'on'"
                state="inactive"
                label="On"
            />
            <AlertStateBadge
                v-else-if="template.status === 'off'"
                state="disabled"
                label="Turned off"
            />
            <span v-else class="text-xs text-muted-foreground">Not set up</span>
        </div>

        <div class="flex justify-end">
            <Button
                v-if="template.status === 'on' && editHref"
                variant="secondary"
                size="sm"
                as-child
            >
                <Link :href="editHref">Edit</Link>
            </Button>
            <Button
                v-else
                size="sm"
                :disabled="busy"
                :data-test="`alert-template-activate-${template.key}`"
                @click="emit('activate')"
            >
                Turn on
            </Button>
        </div>
    </div>
</template>
