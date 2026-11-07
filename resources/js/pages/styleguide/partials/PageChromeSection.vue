<script setup lang="ts">
import { RotateCcw, Undo2 } from '@lucide/vue';
import { ref } from 'vue';
import FactStrip from '@/components/FactStrip.vue';
import FilterSelect from '@/components/FilterSelect.vue';
import KeyHint from '@/components/KeyHint.vue';
import KeyValueList from '@/components/KeyValueList.vue';
import LogsSeverityFilter from '@/components/LogsSeverityFilter.vue';
import PageHeader from '@/components/PageHeader.vue';
import PageSection from '@/components/PageSection.vue';
import SectionHeader from '@/components/SectionHeader.vue';
import SegmentedControl from '@/components/SegmentedControl.vue';
import StorageBusy from '@/components/StorageBusy.vue';
import SurfaceMessage from '@/components/SurfaceMessage.vue';
import TraceFact from '@/components/TraceFact.vue';
import { Button } from '@/components/ui/button';
import type { SeverityLevel } from '@/types';
import DemoBlock from './DemoBlock.vue';

type Status = 'unresolved' | 'resolved' | 'ignored' | 'all';

const status = ref<Status>('unresolved');
const service = ref<string | null>(null);
const period = ref<string | null>('7d');
const severity = ref<SeverityLevel[]>(['error', 'fatal']);
</script>

<template>
    <div class="flex flex-col gap-6">
        <DemoBlock
            title="PageHeader"
            description="The top of every in-app page, set straight on the work surface: the eyebrow (the layout's breadcrumbs minus the page, or an explicit scope), one large title, one summary sentence in which only the figure that matters takes a colour, and the actions on the right."
        >
            <div class="-mx-4 rounded-md ring-1 ring-border">
                <PageHeader
                    title="Issues"
                    :eyebrow="['storefront-web', 'production']"
                    class="md:px-6 md:pt-6"
                >
                    <span class="font-medium text-foreground"
                        >5 unresolved</span
                    >
                    · last 7 days ·
                    <span class="font-medium text-severity-error"
                        >1 regressed</span
                    >
                    <template #actions>
                        <SegmentedControl
                            v-model="status"
                            aria-label="Status"
                            :options="[
                                {
                                    value: 'unresolved',
                                    label: 'Unresolved',
                                    count: 5,
                                },
                                { value: 'resolved', label: 'Resolved' },
                                { value: 'ignored', label: 'Ignored' },
                                { value: 'all', label: 'All' },
                            ]"
                        />
                    </template>
                </PageHeader>
            </div>
        </DemoBlock>

        <DemoBlock
            title="FilterSelect"
            description="A filter as one chip: the label muted inside the trigger, the chosen value in ink beside it, so a row of filters reads as a sentence. Null is 'all', offered first under allLabel."
        >
            <div class="flex flex-wrap items-center gap-2">
                <FilterSelect
                    v-model="period"
                    label="Window"
                    :options="[
                        { value: '24h', label: 'Last 24 hours' },
                        { value: '7d', label: 'Last 7 days' },
                        { value: '30d', label: 'Last 30 days' },
                    ]"
                />
                <FilterSelect
                    v-model="service"
                    label="Service"
                    all-label="All services"
                    :options="[
                        { value: 'storefront-web', label: 'storefront-web' },
                        { value: 'checkout-api', label: 'checkout-api' },
                    ]"
                />
            </div>
        </DemoBlock>

        <DemoBlock
            title="SegmentedControl"
            description="A few mutually exclusive views one click apart. The chosen segment lifts onto the surface with a hairline, never a resting shadow. Options with an href render as links — a section's own tabs, such as Traces and Service latency."
        >
            <div class="flex flex-wrap items-center gap-3">
                <SegmentedControl
                    v-model="status"
                    aria-label="Status"
                    :options="[
                        { value: 'unresolved', label: 'Unresolved', count: 5 },
                        { value: 'resolved', label: 'Resolved' },
                        { value: 'ignored', label: 'Ignored' },
                        { value: 'all', label: 'All' },
                    ]"
                />
                <Button variant="ghost" class="text-muted-foreground">
                    <Undo2 class="size-4" />
                    Step back
                </Button>
                <Button variant="ghost" class="text-muted-foreground">
                    <RotateCcw class="size-4" />
                    Reset
                </Button>
            </div>
        </DemoBlock>

        <DemoBlock
            title="LogsSeverityFilter"
            description="The log viewer's severity filter, drawn as the volume chart's legend: the key to the bar colours and the control that narrows them are one thing. No level picked means every level at full strength; once some are picked, the rest fade."
        >
            <LogsSeverityFilter v-model:severity="severity" />
        </DemoBlock>

        <DemoBlock
            title="KeyHint"
            description="A shortcut as a list footer prints it. Only keys the page really binds — useListCursor answers j/k, Enter/o, y, / and ?."
        >
            <div
                class="flex flex-wrap items-center gap-3.5 text-xs text-muted-foreground"
            >
                <KeyHint :keys="['j', 'k']" label="move" />
                <KeyHint :keys="['↵']" label="open" />
                <KeyHint :keys="['/']" label="search" />
                <KeyHint :keys="['?']" label="all keys" />
            </div>
        </DemoBlock>
        <DemoBlock
            title="Public-page components (Blade)"
            description="The public site is Blade, so its pieces live in resources/views/components/ui and render server-side — but they are cut from the same tokens and utilities as the components above, which is what makes the landing page and the app read as one product. Each one has an app counterpart."
        >
            <div class="divide-y text-sm">
                <div
                    v-for="row in [
                        [
                            'x-ui.eyebrow',
                            'The uppercase line above a title; number= for a numbered section',
                            'PageHeader eyebrow',
                        ],
                        [
                            'x-ui.section-header',
                            'Numbered eyebrow, headline, lead on the right, hairline',
                            'PageHeader',
                        ],
                        [
                            'x-ui.button',
                            'primary (ink) · outline · ghost; size lg',
                            'Button',
                        ],
                        [
                            'x-ui.badge',
                            'neutral · muted · alarm (the one severity wash)',
                            'IssueStatusBadge',
                        ],
                        [
                            'x-ui.figure',
                            'One figure in a hairline strip',
                            'Dashboard KPI strip',
                        ],
                        [
                            'x-ui.panel',
                            'A piece of the product on a public page',
                            'TracePanel / log list',
                        ],
                    ]"
                    :key="row[0]"
                    class="grid gap-1 py-3 sm:grid-cols-[12rem_minmax(0,1fr)_12rem] sm:gap-6"
                >
                    <code class="font-mono text-xs">&lt;{{ row[0] }}&gt;</code>
                    <span class="text-muted-foreground">{{ row[1] }}</span>
                    <span class="text-xs text-muted-foreground sm:text-right">{{
                        row[2]
                    }}</span>
                </div>
            </div>
        </DemoBlock>

        <DemoBlock
            title="SectionHeader"
            description="the heading of every section inside a page: a base-size title over a hairline, a short muted note at the right — a count, a unit, a scope — or the section's own link. Type and a rule, never a box. The dashboard's digest columns and the host chart groups use it bare; PageSection wraps it."
        >
            <div class="flex flex-col gap-6">
                <SectionHeader
                    title="Top errors"
                    note="repeating most often · 24h"
                />
                <SectionHeader title="Trace">
                    <template #aside>
                        <a
                            href="#"
                            class="underline-offset-4 hover:text-foreground hover:underline"
                            >Open trace</a
                        >
                    </template>
                </SectionHeader>
            </div>
        </DemoBlock>

        <DemoBlock
            title="PageSection"
            description="one section of a page on the gutter: SectionHeader, an optional sentence on what it shows, then the body, 40px below the section before it. 'flush' lets a table run edge to edge under a guttered heading; 'collapsible' folds the body behind the heading — the issue page's 'All attributes'."
        >
            <div class="-mx-4 pb-6">
                <PageSection
                    title="Proposed change"
                    description="The validated diff, against main. Machine-generated — read it the way you would read a stranger's patch."
                    class="pt-6"
                >
                    <p class="text-sm text-muted-foreground">
                        The body sits 16px under the heading.
                    </p>
                </PageSection>
                <PageSection title="All attributes" collapsible>
                    <template #aside>
                        <span class="font-mono tabular-nums">3</span>
                    </template>
                    <KeyValueList
                        :entries="[
                            ['code.namespace', 'App\\Payments\\ChargeCard'],
                            ['messaging.batch.size', '1'],
                            ['otel.scope.name', 'bilis.envelope'],
                        ]"
                        wide
                    />
                </PageSection>
            </div>
        </DemoBlock>

        <DemoBlock
            title="FactStrip"
            description="the hairline-bounded strip under a detail page's header — where a thing lives and what it is — one TraceFact per cell: a quiet label over a loud value, mono for machine-authored text, a copy button where the value is worth pasting. Two columns on a phone, three on a tablet, four to six from lg. The issue page and the Autofix job page share it."
        >
            <div class="-mx-4">
                <FactStrip>
                    <TraceFact label="Project" value="Checkout" />
                    <TraceFact label="Service" value="checkout-api" mono />
                    <TraceFact
                        label="Origin"
                        value="ChargeCard.php:41 handle()"
                        mono
                    />
                    <TraceFact label="Environments" value="production" mono />
                    <TraceFact
                        label="Fingerprint"
                        value="a3f1c9e0b7d2"
                        mono
                        copyable
                    />
                </FactStrip>
            </div>
        </DemoBlock>

        <DemoBlock
            title="KeyValueList"
            description="attribute pairs as hairline-ruled rows: the key quiet and truncated in a fixed 9rem lane (12rem with 'wide'), the value in mono taking the rest and breaking anywhere. 'preserve-whitespace' keeps the line breaks a value arrived with."
        >
            <div class="max-w-md">
                <KeyValueList
                    :entries="[
                        ['http.request.method', 'POST'],
                        ['url.path', '/checkout/confirm'],
                        ['http.response.status_code', '500'],
                    ]"
                />
            </div>
        </DemoBlock>

        <DemoBlock
            title="SurfaceMessage · StorageBusy"
            description="a sentence standing in for content that is not there — an empty window, expired events — on the gutter, never in a card: an optional title in ink, the explanation in muted prose. StorageBusy is the one wording for a ClickHouse read that did not answer in time, used by every page and panel; 'inset' off drops the gutter inside a panel that has its own padding."
        >
            <div class="-mx-4 flex flex-col divide-y">
                <SurfaceMessage title="These events have expired">
                    This issue's events are older than the 30 days events are
                    kept, so there is no stack trace or context to show.
                </SurfaceMessage>
                <StorageBusy store="Trace storage" />
            </div>
        </DemoBlock>
    </div>
</template>
