<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Ellipsis, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import AlertStateBadge from '@/components/AlertStateBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    ALERT_SEVERITY_LABEL,
    ALERT_SOURCE_LABEL,
    MUTE_OPTIONS,
    describeQuery,
    formatCondition,
    formatSeconds,
} from '@/lib/alerts';
import { formatRelativeTime } from '@/lib/logs';
import { cn } from '@/lib/utils';
import { edit as alertsEdit } from '@/routes/alerts';
import type { AlertRuleSummary } from '@/types';

/**
 * One rule in the Alerts list: what it watches, where it stands, when it
 * last ran, and where it sends.
 *
 * The left column is read — the name, then the condition in the words the
 * whole product uses ("p95 > 500 ms for 5m") and what it is measured over,
 * then the channels. The right columns are scanned: a state pill, the last
 * evaluation. A rule whose query failed prints its error in ink beneath the
 * channels, because "this rule cannot see" is worth reading before anything
 * it says.
 *
 * A rule Bilis set up itself carries a neutral "Set up by Bilis" mark and its
 * catalogue's one-line reason, and its destructive action is "Turn off": the
 * server keeps the row so provisioning never brings it back.
 *
 * Actions emit; the page owns the requests.
 */
const props = defineProps<{
    rule: AlertRuleSummary;
    teamSlug: string;
}>();

const emit = defineEmits<{
    mute: [until: '1h' | '1d' | '1w' | 'off'];
    toggle: [];
    delete: [];
}>();

const href = computed(() => alertsEdit([props.teamSlug, props.rule.id]).url);

const condition = computed(() =>
    formatCondition({
        source: props.rule.source,
        query: props.rule.query,
        condition: props.rule.condition,
        threshold: props.rule.threshold,
        forSeconds: props.rule.forSeconds,
    }),
);

const measured = computed(
    () =>
        `${describeQuery(props.rule.source, props.rule.query)} · ${formatSeconds(props.rule.windowSeconds)} window`,
);

const state = computed(() => {
    if (!props.rule.enabled) {
        return { state: 'disabled' as const, count: null };
    }

    if (props.rule.health === 'err') {
        return { state: 'error' as const, count: null };
    }

    if (props.rule.firing > 0) {
        return { state: 'firing' as const, count: props.rule.firing };
    }

    if (props.rule.pending > 0) {
        return { state: 'pending' as const, count: props.rule.pending };
    }

    return { state: 'inactive' as const, count: null };
});

const lastEvaluated = computed(() =>
    props.rule.lastEvaluatedAt
        ? formatRelativeTime(props.rule.lastEvaluatedAt)
        : 'Not yet',
);

const mutedUntil = computed(() =>
    props.rule.mutedUntil
        ? new Date(props.rule.mutedUntil).toLocaleString(undefined, {
              month: 'short',
              day: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : null,
);
</script>

<template>
    <div
        :class="
            cn(
                'grid grid-cols-[minmax(0,1fr)_auto_auto] items-center gap-x-6 gap-y-1 border-b border-border/70 px-4 py-4 transition-colors hover:bg-muted/50 md:grid-cols-[minmax(0,1fr)_7.5rem_6.5rem_2.25rem] md:px-10',
                !rule.enabled && 'text-muted-foreground',
            )
        "
        :data-test="`alert-rule-${rule.id}`"
    >
        <div class="flex min-w-0 flex-col gap-1">
            <div class="flex min-w-0 items-center gap-2">
                <Link
                    :href="href"
                    :class="
                        cn(
                            'truncate text-sm font-semibold hover:underline hover:underline-offset-4',
                            rule.enabled
                                ? 'text-foreground'
                                : 'text-muted-foreground',
                        )
                    "
                    :title="rule.name"
                >
                    {{ rule.name }}
                </Link>
                <span class="shrink-0 text-xs text-muted-foreground">
                    {{ ALERT_SOURCE_LABEL[rule.source] }} ·
                    {{ ALERT_SEVERITY_LABEL[rule.severity] }}
                </span>
                <Badge
                    v-if="rule.template"
                    variant="outline"
                    class="font-normal text-muted-foreground"
                    :title="
                        rule.customized
                            ? 'Bilis set this up; it has been edited since, so Bilis no longer updates it.'
                            : 'Bilis set this up for the data you send, and keeps it up to date.'
                    "
                    data-test="alert-rule-template"
                >
                    <Sparkles aria-hidden="true" />
                    Set up by Bilis{{ rule.customized ? ' · edited' : '' }}
                </Badge>
                <AlertStateBadge
                    v-if="mutedUntil"
                    state="muted"
                    :label="`Muted until ${mutedUntil}`"
                />
            </div>

            <p
                v-if="rule.template && rule.why"
                class="line-clamp-2 text-xs text-muted-foreground"
                data-test="alert-rule-why"
            >
                {{ rule.why }}
            </p>

            <p
                class="truncate font-mono text-xs text-foreground/80"
                :title="`${condition} — ${measured}`"
            >
                <span class="font-medium text-foreground">{{ condition }}</span>
                <span class="text-muted-foreground"> · {{ measured }}</span>
            </p>

            <p class="truncate text-xs text-muted-foreground">
                <template v-if="rule.channels.length">
                    Notifies
                    <span
                        v-for="(channel, index) in rule.channels"
                        :key="channel.id"
                        :class="!channel.enabled && 'line-through'"
                        >{{ channel.name
                        }}{{
                            index < rule.channels.length - 1 ? ', ' : ''
                        }}</span
                    >
                </template>
                <template v-else>No channels — records history only</template>
            </p>

            <p
                v-if="rule.health === 'err' && rule.lastError"
                class="line-clamp-2 text-xs text-foreground"
                data-test="alert-rule-error"
            >
                {{ rule.lastError }}
            </p>
        </div>

        <div class="flex justify-end md:justify-start">
            <AlertStateBadge :state="state.state" :count="state.count" />
        </div>

        <div
            class="hidden text-right text-xs text-muted-foreground tabular-nums md:block"
            :title="rule.lastEvaluatedAt ?? undefined"
        >
            {{ lastEvaluated }}
            <span class="block text-[11px]"
                >every {{ formatSeconds(rule.intervalSeconds) }}</span
            >
        </div>

        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-8 text-muted-foreground"
                    :aria-label="`Actions for ${rule.name}`"
                    :data-test="`alert-rule-actions-${rule.id}`"
                >
                    <Ellipsis class="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-48">
                <DropdownMenuItem as-child>
                    <Link :href="href">Edit</Link>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuLabel class="text-xs text-muted-foreground"
                    >Mute notifications</DropdownMenuLabel
                >
                <DropdownMenuItem
                    v-for="option in MUTE_OPTIONS"
                    :key="option.value"
                    :data-test="`alert-rule-mute-${option.value}`"
                    @select="emit('mute', option.value)"
                >
                    {{ option.label }}
                </DropdownMenuItem>
                <DropdownMenuItem
                    v-if="rule.mutedUntil"
                    data-test="alert-rule-unmute"
                    @select="emit('mute', 'off')"
                >
                    Unmute
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <!--
                  A template rule's "Turn off" is its disable, so it never
                  offers both: Turn off while on, Enable while off.
                -->
                <DropdownMenuItem
                    v-if="!rule.template || !rule.enabled"
                    data-test="alert-rule-toggle"
                    @select="emit('toggle')"
                >
                    {{ rule.enabled ? 'Disable' : 'Enable' }}
                </DropdownMenuItem>
                <DropdownMenuItem
                    v-if="!rule.template || rule.enabled"
                    :class="
                        !rule.template &&
                        'text-destructive focus:text-destructive'
                    "
                    data-test="alert-rule-delete"
                    @select="emit('delete')"
                >
                    {{ rule.template ? 'Turn off' : 'Delete' }}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
