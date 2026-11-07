<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Wrench } from '@lucide/vue';
import { computed, ref } from 'vue';
import AutofixUpsellModal from '@/components/AutofixUpsellModal.vue';
import FactStrip from '@/components/FactStrip.vue';
import IssueStatusBadge from '@/components/IssueStatusBadge.vue';
import PageHeader from '@/components/PageHeader.vue';
import RunAutofixModal from '@/components/RunAutofixModal.vue';
import TraceFact from '@/components/TraceFact.vue';
import { Button } from '@/components/ui/button';
import {
    DECISION_LABEL,
    displayLocation,
    formatCount,
    isOpen,
    isoFromMs,
} from '@/lib/issues';
import { autofixTargetFor } from '@/lib/logRow';
import { formatRelativeTime } from '@/lib/logs';
import { update as issueUpdate } from '@/routes/issues';
import type {
    IssueDecision,
    IssueDetail,
    IssueException,
    LogAutofixState,
    LogEntry,
} from '@/types';

/**
 * The top of an issue page: what broke, how often, where it stands, and the
 * buttons that decide what happens next — then the strip of where it lives.
 */
const props = defineProps<{
    issue: IssueDetail;
    exception: IssueException | null;
    /** The event on screen; Autofix is raised from it. */
    row: LogEntry | null;
    autofix: LogAutofixState;
    teamSlug: string;
}>();

const title = computed(
    () => props.exception?.type || props.issue.type || 'Unknown exception',
);

const message = computed(() => props.exception?.message || props.issue.message);

const handled = computed(
    () => props.row?.logAttributes['exception.handled'] ?? null,
);

const mechanism = computed(
    () => props.row?.logAttributes['exception.mechanism'] ?? null,
);

const lastSeen = computed(() =>
    formatRelativeTime(isoFromMs(props.issue.lastSeen)),
);

const firstSeen = computed(() =>
    formatRelativeTime(isoFromMs(props.issue.firstSeen)),
);

const target = computed(() =>
    props.row ? autofixTargetFor(props.row, props.autofix) : null,
);

/*
 * Triage. A PATCH that comes back to this page; preserveScroll so resolving
 * an issue from halfway down its stack does not throw the reader to the top.
 */
const deciding = ref<IssueDecision | null>(null);

function decide(status: IssueDecision) {
    deciding.value = status;

    router.patch(
        issueUpdate({
            current_team: props.teamSlug,
            project: props.issue.projectSlug,
            fingerprint: props.issue.fingerprint,
        }).url,
        { status },
        {
            preserveScroll: true,
            // Only the status changed: the charts, tags and events stay.
            only: ['issue'],
            onFinish: () => {
                deciding.value = null;
            },
        },
    );
}
</script>

<template>
    <div data-test="issue-header">
        <PageHeader :title="title">
            <template #title>
                <span class="flex min-w-0 items-center gap-3">
                    <span class="truncate font-mono" :title="title">{{
                        title
                    }}</span>
                    <IssueStatusBadge :status="issue.status" />
                    <span
                        v-if="handled === 'false'"
                        class="inline-flex h-5 shrink-0 items-center rounded-full bg-severity-error/10 px-2 font-sans text-[11px] font-medium tracking-normal text-severity-error"
                        :title="
                            mechanism ? `Caught by ${mechanism}` : undefined
                        "
                        data-test="issue-unhandled"
                    >
                        Unhandled
                    </span>
                </span>
            </template>

            <div class="flex max-w-4xl flex-col gap-1.5">
                <p
                    v-if="message"
                    class="break-words whitespace-pre-wrap text-foreground/80"
                    data-test="issue-message"
                >
                    {{ message }}
                </p>
                <p data-test="issue-summary">
                    <span class="font-medium text-foreground"
                        >{{ formatCount(issue.events) }}
                        {{ issue.events === 1 ? 'event' : 'events' }}</span
                    >
                    <template v-if="issue.users > 0">
                        · {{ formatCount(issue.users) }}
                        {{ issue.users === 1 ? 'user' : 'users' }}
                    </template>
                    · first seen {{ firstSeen
                    }}<template v-if="issue.firstRelease">
                        in
                        <span class="font-mono">{{
                            issue.firstRelease
                        }}</span></template
                    >
                    · last seen {{ lastSeen
                    }}<template v-if="issue.lastRelease">
                        in
                        <span class="font-mono">{{
                            issue.lastRelease
                        }}</span></template
                    >
                </p>
                <p
                    v-if="issue.status === 'regressed' && issue.resolvedAt"
                    data-test="issue-regressed-note"
                >
                    Resolved {{ formatRelativeTime(issue.resolvedAt)
                    }}<template v-if="issue.statusChangedBy">
                        by {{ issue.statusChangedBy }}</template
                    >, and
                    <span class="font-medium text-severity-error"
                        >seen again {{ lastSeen }}</span
                    >.
                </p>
                <p
                    v-else-if="
                        issue.statusChangedBy &&
                        issue.statusChangedAt &&
                        issue.status !== 'unresolved'
                    "
                >
                    Marked {{ issue.status }} by
                    {{ issue.statusChangedBy }}
                    {{ formatRelativeTime(issue.statusChangedAt) }}.
                </p>
            </div>

            <template #actions>
                <template v-if="isOpen(issue.status)">
                    <Button
                        type="button"
                        :disabled="deciding !== null"
                        data-test="issue-resolve"
                        @click="decide('resolved')"
                    >
                        {{ DECISION_LABEL.resolved }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="deciding !== null"
                        data-test="issue-ignore"
                        @click="decide('ignored')"
                    >
                        {{ DECISION_LABEL.ignored }}
                    </Button>
                </template>
                <Button
                    v-else
                    type="button"
                    variant="outline"
                    :disabled="deciding !== null"
                    data-test="issue-reopen"
                    @click="decide('unresolved')"
                >
                    {{ DECISION_LABEL.unresolved }}
                </Button>

                <template v-if="row && target">
                    <RunAutofixModal
                        v-if="target.repository"
                        :entry="row"
                        :team-slug="teamSlug"
                        :repository="target.repository"
                        :credentials="autofix.credentials"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            data-test="issue-autofix"
                        >
                            <Wrench class="size-3.5" /> Fix with Autofix
                        </Button>
                    </RunAutofixModal>
                    <AutofixUpsellModal
                        v-else
                        :entry="row"
                        :team-slug="teamSlug"
                        :project-slug="target.project?.slug ?? null"
                        :project-name="target.project?.name ?? null"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            data-test="issue-autofix-upsell"
                        >
                            <Wrench class="size-3.5" /> Fix with Autofix
                        </Button>
                    </AutofixUpsellModal>
                </template>
            </template>
        </PageHeader>

        <FactStrip data-test="issue-facts">
            <TraceFact label="Project" :value="issue.projectName" />
            <TraceFact
                label="Service"
                :value="issue.services.join(', ') || issue.service || '—'"
                mono
            />
            <TraceFact
                label="Origin"
                :value="issue.origin ? displayLocation(issue.origin) : '—'"
                mono
            />
            <TraceFact
                label="Environments"
                :value="issue.environments.join(', ') || '—'"
                mono
            />
            <TraceFact
                label="Fingerprint"
                :value="issue.fingerprint.slice(0, 12)"
                mono
                copyable
            />
        </FactStrip>
    </div>
</template>
