<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import AlertEventRow from '@/components/AlertEventRow.vue';
import AlertInstanceRow from '@/components/AlertInstanceRow.vue';
import AlertRuleRow from '@/components/AlertRuleRow.vue';
import PageHeader from '@/components/PageHeader.vue';
import PageSection from '@/components/PageSection.vue';
import SegmentedControl from '@/components/SegmentedControl.vue';
import SurfaceMessage from '@/components/SurfaceMessage.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    create as alertsCreate,
    destroy as alertsDestroy,
    history as alertsHistory,
    index as alertsIndex,
    mute as alertsMute,
    toggle as alertsToggle,
} from '@/routes/alerts';
import { index as projectsIndex } from '@/routes/projects';
import { edit as teamEdit } from '@/routes/teams';
import type {
    AlertEventSummary,
    AlertInstanceSummary,
    AlertRuleSummary,
    Team,
} from '@/types';

const props = withDefaults(
    defineProps<{
        tab: 'rules' | 'history';
        hasChannels: boolean;
        hasProjects: boolean;
        ruleCount: number;
        /** Rules tab. */
        rules?: AlertRuleSummary[];
        instances?: AlertInstanceSummary[];
        /** Enabled rules more than one interval late. */
        overdue?: number;
        /** Enabled rules Bilis set up from its catalogue. */
        templateCount?: number;
        /** History tab. */
        events?: AlertEventSummary[];
        historyLimit?: number;
    }>(),
    {
        rules: () => [],
        instances: () => [],
        overdue: 0,
        templateCount: 0,
        events: () => [],
        historyLimit: 100,
    },
);

defineOptions({
    layout: (layoutProps: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Alerts',
                href: layoutProps.currentTeam
                    ? alertsIndex(layoutProps.currentTeam.slug)
                    : '/',
            },
        ],
    }),
});

const page = usePage();

const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

const firing = computed(
    () =>
        props.instances.filter((instance) => instance.state === 'firing')
            .length,
);

const pending = computed(
    () =>
        props.instances.filter((instance) => instance.state === 'pending')
            .length,
);

const failing = computed(
    () =>
        props.rules.filter((rule) => rule.enabled && rule.health === 'err')
            .length,
);

const tabs = computed(() => [
    {
        value: 'rules' as const,
        label: 'Rules',
        href: alertsIndex(teamSlug.value).url,
        count: props.ruleCount,
    },
    {
        value: 'history' as const,
        label: 'History',
        href: alertsHistory(teamSlug.value).url,
    },
]);

const channelsHref = computed(
    () => `${teamEdit(teamSlug.value).url}#notification-channels`,
);

function mute(rule: AlertRuleSummary, until: '1h' | '1d' | '1w' | 'off') {
    router.post(
        alertsMute([teamSlug.value, rule.id]).url,
        { until },
        { preserveScroll: true },
    );
}

function toggle(rule: AlertRuleSummary) {
    router.post(
        alertsToggle([teamSlug.value, rule.id]).url,
        {},
        { preserveScroll: true },
    );
}

/*
 * The "Bilis set up N alerts" banner, dismissed per person in this browser.
 * The count dismissed is stored, so a new template rule brings it back.
 * Storage can throw (private windows, blocked site data); the banner then
 * simply shows, and dismissing still hides it for this visit.
 */
const bannerKey = computed(
    () =>
        `bilis:alerts:templates-banner:${page.props.auth?.user?.id ?? 'guest'}:${teamSlug.value}`,
);

const dismissedTemplateCount = ref<number | null>(null);

onMounted(() => {
    try {
        const stored = Number(localStorage.getItem(bannerKey.value));

        dismissedTemplateCount.value = Number.isFinite(stored) ? stored : 0;
    } catch {
        dismissedTemplateCount.value = 0;
    }
});

const showTemplateBanner = computed(
    () =>
        props.tab === 'rules' &&
        props.templateCount > 0 &&
        dismissedTemplateCount.value !== null &&
        props.templateCount > dismissedTemplateCount.value,
);

function dismissTemplateBanner() {
    dismissedTemplateCount.value = props.templateCount;

    try {
        localStorage.setItem(bannerKey.value, String(props.templateCount));
    } catch {
        // Hidden for this visit only.
    }
}

const deleting = ref<AlertRuleSummary | null>(null);

function confirmDelete() {
    if (!deleting.value) {
        return;
    }

    router.delete(alertsDestroy([teamSlug.value, deleting.value.id]).url, {
        preserveScroll: true,
        onSuccess: () => (deleting.value = null),
    });
}
</script>

<template>
    <Head :title="tab === 'history' ? 'Alert history' : 'Alerts'" />

    <div class="flex flex-1 flex-col">
        <PageHeader title="Alerts">
            <p data-test="alerts-summary">
                <template v-if="tab === 'rules' && ruleCount > 0">
                    <span
                        :class="
                            firing > 0
                                ? 'font-medium text-severity-error'
                                : 'font-medium text-foreground'
                        "
                        >{{ firing }} firing</span
                    >
                    · {{ pending }} pending · {{ ruleCount }}
                    {{ ruleCount === 1 ? 'rule' : 'rules' }}
                    <template v-if="failing > 0">
                        ·
                        <span class="font-medium text-foreground"
                            >{{ failing }} failing to evaluate</span
                        >
                    </template>
                </template>
                <template v-else-if="tab === 'history'">
                    Firing, resolved and rule errors, newest first
                </template>
                <template v-else>
                    Threshold rules over your logs, traces and metrics
                </template>
            </p>

            <template #actions>
                <SegmentedControl
                    :model-value="tab"
                    :options="tabs"
                    aria-label="Alert views"
                    data-test-prefix="alerts-tab"
                />
                <Button v-if="hasProjects" as-child data-test="alerts-create">
                    <Link :href="alertsCreate(teamSlug).url">
                        <Plus /> New alert rule
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <!--
          Two facts worth a band of their own: the loop is behind, or there is
          nowhere to send what it finds.
        -->
        <div
            v-if="tab === 'rules' && overdue > 0"
            class="mx-4 mb-4 rounded-lg border bg-muted/40 px-4 py-3 text-sm md:mx-10"
            role="status"
            data-test="alerts-overdue"
        >
            <span class="font-medium text-foreground"
                >{{ overdue }}
                {{ overdue === 1 ? 'rule is' : 'rules are' }} overdue.</span
            >
            <span class="text-muted-foreground">
                Evaluation is running behind its schedule — the scheduler or the
                alerts queue is not keeping up, so alerts may arrive late.
            </span>
        </div>

        <div
            v-if="showTemplateBanner"
            class="mx-4 mb-4 flex items-start gap-3 rounded-lg border bg-muted/40 px-4 py-3 text-sm md:mx-10"
            role="status"
            data-test="alerts-templates-banner"
        >
            <p class="flex-1">
                <span class="font-medium text-foreground"
                    >Bilis set up {{ templateCount }}
                    {{ templateCount === 1 ? 'alert' : 'alerts' }} for the data
                    you send.</span
                >
                <span class="text-muted-foreground">
                    Each one says why it is there. Tune any of them, or turn it
                    off — Bilis will not set it up again.
                </span>
            </p>
            <Button
                variant="ghost"
                size="icon"
                class="-my-1 size-7 shrink-0 text-muted-foreground"
                aria-label="Dismiss"
                data-test="alerts-templates-banner-dismiss"
                @click="dismissTemplateBanner"
            >
                <X class="size-4" />
            </Button>
        </div>

        <p
            v-if="ruleCount > 0 && !hasChannels"
            class="px-4 pb-4 text-sm text-muted-foreground md:px-10"
            data-test="alerts-no-channels"
        >
            No notification channels yet, so rules record history and send
            nothing.
            <Link
                :href="channelsHref"
                class="underline underline-offset-4 hover:text-foreground"
                >Add one in team settings</Link
            >.
        </p>

        <template v-if="tab === 'rules'">
            <!-- No rules yet: say what one is, and offer the first. -->
            <div
                v-if="ruleCount === 0"
                class="flex flex-col items-start gap-4 border-t px-4 py-8 md:px-10"
                data-test="alerts-empty"
            >
                <SurfaceMessage title="No alert rules yet" :inset="false">
                    A rule watches a count, rate, ratio or percentile in your
                    logs, traces or metrics and tells your team when it crosses
                    a line — and again when it comes back.
                    <template v-if="!hasProjects">
                        It needs a project to read from first:
                        <Link
                            :href="projectsIndex(teamSlug).url"
                            class="underline underline-offset-4 hover:text-foreground"
                            >create one</Link
                        >.
                    </template>
                </SurfaceMessage>
                <Button v-if="hasProjects" as-child>
                    <Link :href="alertsCreate(teamSlug).url">
                        Create alert rule
                    </Link>
                </Button>
                <p
                    v-if="!hasChannels"
                    class="max-w-prose text-xs text-muted-foreground"
                >
                    To be told by email, Slack or webhook,
                    <Link
                        :href="channelsHref"
                        class="underline underline-offset-4 hover:text-foreground"
                        >add a notification channel</Link
                    >
                    in team settings.
                </p>
            </div>

            <template v-else>
                <PageSection
                    title="Firing and pending"
                    :note="
                        instances.length
                            ? `${instances.length} series`
                            : undefined
                    "
                    flush
                    class="pt-2"
                    data-test="alerts-instances"
                >
                    <div v-if="instances.length" class="mt-3 border-t">
                        <AlertInstanceRow
                            v-for="instance in instances"
                            :key="instance.id"
                            :instance="instance"
                            :team-slug="teamSlug"
                        />
                    </div>
                    <p
                        v-else
                        class="px-4 pt-3 text-sm text-muted-foreground md:px-10"
                        data-test="alerts-all-clear"
                    >
                        Nothing is firing. Series appear here as soon as a
                        condition holds, and stay pending until its
                        <span class="font-mono">for</span> has passed.
                    </p>
                </PageSection>

                <PageSection
                    title="Rules"
                    flush
                    class="pb-8"
                    data-test="alerts-rules"
                >
                    <div class="mt-3 border-t">
                        <header
                            class="grid grid-cols-[minmax(0,1fr)_auto_auto] items-center gap-x-6 border-b column-header px-4 py-2.5 md:grid-cols-[minmax(0,1fr)_7.5rem_6.5rem_2.25rem] md:px-10"
                        >
                            <span>Rule</span>
                            <span>State</span>
                            <span class="hidden text-right md:block"
                                >Evaluated</span
                            >
                            <span class="sr-only">Actions</span>
                        </header>
                        <AlertRuleRow
                            v-for="rule in rules"
                            :key="rule.id"
                            :rule="rule"
                            :team-slug="teamSlug"
                            @mute="mute(rule, $event)"
                            @toggle="toggle(rule)"
                            @delete="deleting = rule"
                        />
                    </div>
                </PageSection>
            </template>
        </template>

        <section
            v-else
            class="flex flex-1 flex-col border-t"
            data-test="alerts-history"
        >
            <template v-if="events.length">
                <header
                    class="hidden items-center gap-x-5 border-b column-header px-4 py-2.5 md:grid md:grid-cols-[8rem_7.5rem_minmax(0,1fr)_6rem_5rem] md:px-10"
                >
                    <span>When</span>
                    <span>Event</span>
                    <span>Rule</span>
                    <span class="text-right">Value</span>
                    <span class="sr-only">Link</span>
                </header>
                <AlertEventRow
                    v-for="event in events"
                    :key="event.id"
                    :event="event"
                    :team-slug="teamSlug"
                />
                <footer
                    class="mt-auto border-t px-4 py-3 text-xs text-muted-foreground md:px-10"
                >
                    {{
                        events.length >= historyLimit
                            ? `The latest ${historyLimit} events`
                            : `${events.length} ${events.length === 1 ? 'event' : 'events'}`
                    }}
                    · kept for 90 days
                </footer>
            </template>
            <SurfaceMessage
                v-else
                title="No alert history yet"
                data-test="alerts-history-empty"
            >
                Every time a series starts firing, resolves, or a rule cannot be
                evaluated, it is written here — and kept for 90 days.
            </SurfaceMessage>
        </section>

        <Dialog
            :open="deleting !== null"
            @update:open="(open) => !open && (deleting = null)"
        >
            <DialogContent>
                <DialogHeader v-if="deleting?.template">
                    <DialogTitle>Turn off {{ deleting.name }}</DialogTitle>
                    <DialogDescription>
                        Bilis set this rule up. Turned off, it stops evaluating
                        and Bilis will not set it up again. You can switch it
                        back on from this list at any time.
                    </DialogDescription>
                </DialogHeader>
                <DialogHeader v-else>
                    <DialogTitle>Delete {{ deleting?.name }}</DialogTitle>
                    <DialogDescription>
                        The rule stops evaluating and its firing series are
                        dropped without a resolved notification. Its history
                        stays.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>
                    <Button
                        :variant="
                            deleting?.template ? 'default' : 'destructive'
                        "
                        data-test="alerts-delete-confirm"
                        @click="confirmDelete"
                        >{{
                            deleting?.template ? 'Turn off' : 'Delete rule'
                        }}</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
