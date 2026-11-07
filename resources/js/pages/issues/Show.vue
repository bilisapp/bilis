<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BreadcrumbList from '@/components/BreadcrumbList.vue';
import IssueEventContext from '@/components/IssueEventContext.vue';
import IssueEventNav from '@/components/IssueEventNav.vue';
import IssueException from '@/components/IssueException.vue';
import IssueHeader from '@/components/IssueHeader.vue';
import IssueHistogram from '@/components/IssueHistogram.vue';
import IssueRecentEvents from '@/components/IssueRecentEvents.vue';
import IssueTagBars from '@/components/IssueTagBars.vue';
import KeyValueList from '@/components/KeyValueList.vue';
import PageSection from '@/components/PageSection.vue';
import StorageBusy from '@/components/StorageBusy.vue';
import SurfaceMessage from '@/components/SurfaceMessage.vue';
import { eventBreadcrumbs, remainingAttributes } from '@/lib/issues';
import { index as issuesIndex } from '@/routes/issues';
import type {
    IssueDetail,
    IssueEvent,
    IssueEventList,
    IssueException as IssueExceptionData,
    IssueHistograms,
    IssueTagResult,
    LogAutofixState,
    Team,
} from '@/types';

const props = defineProps<{
    issue: IssueDetail;
    event: IssueEvent;
    exception: IssueExceptionData | null;
    autofix: LogAutofixState;
    /** Deferred: events per hour (24h) and per day (30d). */
    histogram?: IssueHistograms;
    /** Deferred with the recent events: top values per tag. */
    tags?: IssueTagResult;
    recentEvents?: IssueEventList;
}>();

defineOptions({
    /*
     * Issues / project / this issue. The page header prints every crumb but
     * the last as its eyebrow, so the way back is the list and the list
     * narrowed to this issue's project.
     */
    layout: (layoutProps: {
        currentTeam?: Team | null;
        issue?: IssueDetail;
    }) => ({
        breadcrumbs: [
            {
                title: 'Issues',
                href: layoutProps.currentTeam
                    ? issuesIndex(layoutProps.currentTeam.slug)
                    : '/',
            },
            ...(layoutProps.issue
                ? [
                      {
                          title: layoutProps.issue.projectName,
                          href: layoutProps.currentTeam
                              ? issuesIndex(layoutProps.currentTeam.slug, {
                                    query: {
                                        project: layoutProps.issue.projectSlug,
                                    },
                                })
                              : '/',
                      },
                  ]
                : []),
            {
                title: layoutProps.issue?.type || 'Issue',
                href: '#',
            },
        ],
    }),
});

const page = usePage();

const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

const row = computed(() => props.event.row);

const title = computed(
    () => props.exception?.type || props.issue.type || 'Unknown exception',
);

const stacktrace = computed(() => props.exception?.stacktrace ?? '');

const breadcrumbs = computed(() => eventBreadcrumbs(row.value));

const attributes = computed(() => remainingAttributes(row.value));
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-1 flex-col">
        <IssueHeader
            :issue="issue"
            :exception="exception"
            :row="row"
            :autofix="autofix"
            :team-slug="teamSlug"
        />

        <!-- How often: the issue's own volume, by the hour or the day. -->
        <div class="px-4 py-5 md:px-10">
            <IssueHistogram :histogram="histogram" />
        </div>

        <div
            class="grid flex-1 grid-cols-1 border-t xl:grid-cols-[minmax(0,1fr)_22rem]"
        >
            <div class="flex min-w-0 flex-col pb-10">
                <IssueEventNav
                    :event="event"
                    :team-slug="teamSlug"
                    :project-slug="issue.projectSlug"
                    :fingerprint="issue.fingerprint"
                />

                <StorageBusy v-if="event.unavailable" store="Event storage" />
                <SurfaceMessage
                    v-else-if="event.expired"
                    title="These events have expired"
                    data-test="issue-events-expired"
                >
                    This issue's events are older than the 30 days events are
                    kept, so there is no stack trace or context to show. Its
                    summary above — type, message, origin, counts and releases —
                    is kept for as long as the issue exists.
                </SurfaceMessage>

                <template v-if="row">
                    <IssueException
                        :row="row"
                        :stacktrace="stacktrace"
                        class="pt-8"
                        data-test="issue-exception"
                    />

                    <PageSection
                        title="Breadcrumbs"
                        :note="
                            breadcrumbs.length > 0
                                ? `${breadcrumbs.length} · most recent first`
                                : undefined
                        "
                    >
                        <BreadcrumbList :breadcrumbs="breadcrumbs" />
                    </PageSection>

                    <IssueEventContext :row="row" />

                    <PageSection
                        v-if="attributes.length > 0"
                        title="All attributes"
                        collapsible
                    >
                        <template #aside>
                            <span class="font-mono tabular-nums">{{
                                attributes.length
                            }}</span>
                        </template>
                        <KeyValueList
                            :entries="attributes"
                            wide
                            preserve-whitespace
                        />
                    </PageSection>
                </template>

                <IssueRecentEvents
                    :events="recentEvents"
                    :team-slug="teamSlug"
                    :project-slug="issue.projectSlug"
                    :fingerprint="issue.fingerprint"
                    :current="
                        row
                            ? {
                                  timestamp: row.timestamp,
                                  eventKey: event.eventKey,
                              }
                            : null
                    "
                />
            </div>

            <!-- Who and where it happens to, across the newest events. -->
            <aside
                class="flex min-w-0 flex-col border-t pb-10 xl:border-t-0 xl:border-l"
            >
                <PageSection
                    title="Tags"
                    note="share of events"
                    class="pt-8 xl:pl-8"
                >
                    <IssueTagBars :tags="tags" />
                </PageSection>
            </aside>
        </div>
    </div>
</template>
