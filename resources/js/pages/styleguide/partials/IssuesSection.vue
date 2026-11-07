<script setup lang="ts">
import { ref } from 'vue';
import BreadcrumbList from '@/components/BreadcrumbList.vue';
import IssueListRow from '@/components/IssueListRow.vue';
import IssueStatusBadge from '@/components/IssueStatusBadge.vue';
import IssuesToolbar from '@/components/IssuesToolbar.vue';
import IssueTagBars from '@/components/IssueTagBars.vue';
import StackTrace from '@/components/StackTrace.vue';
import { parseStackTrace } from '@/lib/issues';
import {
    DEMO_ISSUE_BREADCRUMBS,
    DEMO_ISSUE_FRAMES,
    DEMO_ISSUE_TAGS,
    DEMO_ISSUE_TEXT_STACK,
    DEMO_ISSUES,
} from '@/pages/styleguide/data';
import type { IssuePeriod, IssueSort, IssueStatusFilter } from '@/types';
import DemoBlock from './DemoBlock.vue';

const demoStatus = ref<IssueStatusFilter>('unresolved');
const demoPeriod = ref<IssuePeriod>('7d');
const demoSort = ref<IssueSort>('last_seen');
const demoQuery = ref<string | null>(null);
const demoProject = ref<string | null>(null);
const demoService = ref<string | null>(null);
const demoEnvironment = ref<string | null>(null);

const textFrames = parseStackTrace(DEMO_ISSUE_TEXT_STACK);
</script>

<template>
    <div class="flex flex-col gap-6">
        <DemoBlock
            title="IssueListRow"
            description="one issue in the list. The exception type is read first, then its message, then where it was thrown and the release that last saw it; the right-hand columns are scanned — a severity-tinted dithered trend over the window, the window's events and users, how long ago it last happened and how old it is. 'New' marks an issue first seen inside the window and a regressed issue says so in the severity-error token, because those are the two reasons to open one before anything else. The middle row is held by the keyboard cursor, as j/k leave it on the real page."
        >
            <div class="overflow-hidden rounded-md border bg-card">
                <IssueListRow
                    v-for="(issue, index) in DEMO_ISSUES"
                    :key="issue.fingerprint"
                    :issue="issue"
                    team-slug="acme"
                    :cursor="index === 1"
                />
            </div>
        </DemoBlock>

        <DemoBlock
            title="IssueStatusBadge"
            description="where an issue stands. Achromatic, except regressed: an issue resolved and then seen again is the one status that has to be noticed before it is read, so it borrows the severity-error token. Regressed is never stored — it is derived from the resolution time and the last event."
        >
            <div class="flex flex-wrap gap-2">
                <IssueStatusBadge status="unresolved" />
                <IssueStatusBadge status="regressed" />
                <IssueStatusBadge status="resolved" />
                <IssueStatusBadge status="ignored" />
            </div>
        </DemoBlock>

        <DemoBlock
            title="IssuesToolbar"
            description="search over type, message and origin; status as tabs because triage lives there; then window, sort and scope. Every control emits and the page owns the query string, so a filtered list is a link."
        >
            <IssuesToolbar
                :projects="[
                    { name: 'Checkout', slug: 'checkout' },
                    { name: 'Billing', slug: 'billing' },
                ]"
                :project="demoProject"
                :services="['billing-worker', 'checkout-api', 'storefront']"
                :service="demoService"
                :environments="['production', 'staging']"
                :environment="demoEnvironment"
                :status="demoStatus"
                :period="demoPeriod"
                :periods="['24h', '7d', '14d', '30d']"
                :sort="demoSort"
                :query="demoQuery"
                :can-reset="demoStatus !== 'unresolved' || demoQuery !== null"
                @update:project="demoProject = $event"
                @update:service="demoService = $event"
                @update:environment="demoEnvironment = $event"
                @update:status="demoStatus = $event"
                @update:period="demoPeriod = $event"
                @update:sort="demoSort = $event"
                @update:query="demoQuery = $event"
                @reset="
                    demoStatus = 'unresolved';
                    demoQuery = null;
                "
            />
        </DemoBlock>

        <DemoBlock
            title="StackTrace — structured frames"
            description="an SDK event's frames, most recent call first. The application's frames are first-class; a run of library frames folds into one row until asked for, and 'Full' unfolds them all. The first application frame with source opens on its own, rendered through CodeCanvas with real line numbers and the failing line selected — the line the reader came for. 'Raw' is the trace as received, which is what gets pasted into a ticket."
        >
            <StackTrace :frames="DEMO_ISSUE_FRAMES" />
        </DemoBlock>

        <DemoBlock
            title="StackTrace — parsed from text"
            description="the same component fed from a printed stack: an OTel log carries exception.stacktrace as one string, so lib/issues.ts parses it (PHP, V8, Java, Python, Ruby and Go dialects) into the same frames. No source context travels in text, so no frame opens; node_modules frames are folded as library code."
        >
            <StackTrace :frames="textFrames" :raw="DEMO_ISSUE_TEXT_STACK" />
        </DemoBlock>

        <DemoBlock
            title="BreadcrumbList"
            description="what the application did just before it failed, most recent first. Only a crumb that is itself an error takes the severity token; the rest stay quiet so the list does not become a second severity chart."
        >
            <BreadcrumbList :breadcrumbs="DEMO_ISSUE_BREADCRUMBS" />
        </DemoBlock>

        <DemoBlock
            title="IssueTagBars"
            description="who and where an issue happens to: the top values of each tag as shares of the events that carried it. The bars are a quantity, not a category, so they take the magnitude ladder, and the percentage is always printed beside them. 'User' is the first non-empty identity the event carried — the same expression the users count is built from."
        >
            <div class="max-w-sm">
                <IssueTagBars :tags="DEMO_ISSUE_TAGS" />
            </div>
        </DemoBlock>
    </div>
</template>
