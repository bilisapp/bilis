<script setup lang="ts">
import { ref } from 'vue';
import AlertError from '@/components/AlertError.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import LogEntryRow from '@/components/LogEntryRow.vue';
import LogsToolbar from '@/components/LogsToolbar.vue';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import TextLink from '@/components/TextLink.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SEVERITY_LEVELS } from '@/lib/logs';
import { demoLogEntry } from '@/pages/styleguide/data';
import { styleguide } from '@/routes';
import type { LogProject, LogRangePreset, SeverityLevel } from '@/types';
import DemoBlock from './DemoBlock.vue';
import SectionShell from './SectionShell.vue';

const demoEntries = SEVERITY_LEVELS.map((level, index) => ({
    level,
    entry: demoLogEntry(level, index),
}));

const expandedLevel = ref<SeverityLevel | null>('error');

const toggleExpanded = (level: SeverityLevel) => {
    expandedLevel.value = expandedLevel.value === level ? null : level;
};

const demoProjects: LogProject[] = [
    { name: 'checkout-api', slug: 'checkout-api' },
    { name: 'bilis-ingest', slug: 'bilis-ingest' },
];

const toolbarProject = ref<string | null>('checkout-api');
const toolbarService = ref<string | null>(null);
const toolbarSeverity = ref<SeverityLevel[]>(['warn', 'error', 'fatal']);
const toolbarSearch = ref<string | null>('connection reset');
const toolbarRange = ref<LogRangePreset>('15m');
const toolbarLiveTail = ref(true);
</script>

<template>
    <SectionShell
        id="app-components"
        title="App components"
        description="The Bilis-specific pieces that sit on top of the primitives. Everything below is the real component with demo data, so these blocks double as a regression check when the log UI changes."
    >
        <DemoBlock
            title="LogEntryRow"
            description="one row per severity bucket; the error row starts expanded to show the attribute panel"
        >
            <div class="overflow-x-auto rounded-md border">
                <LogEntryRow
                    v-for="demo in demoEntries"
                    :key="demo.level"
                    :entry="demo.entry"
                    :expanded="expandedLevel === demo.level"
                    @toggle="toggleExpanded(demo.level)"
                />
            </div>
        </DemoBlock>

        <DemoBlock
            title="LogsToolbar"
            description="wired to local refs, so the controls move but nothing is fetched"
        >
            <LogsToolbar
                :projects="demoProjects"
                :project="toolbarProject"
                :service="toolbarService"
                :severity="toolbarSeverity"
                :search="toolbarSearch"
                :range="toolbarRange"
                from="2026-08-26T08:59:00.000Z"
                to="2026-08-26T09:14:00.000Z"
                :live-tail="toolbarLiveTail"
                :tailing="toolbarLiveTail"
                @update:project="toolbarProject = $event"
                @update:service="toolbarService = $event"
                @update:severity="toolbarSeverity = $event"
                @update:search="toolbarSearch = $event"
                @update:range="toolbarRange = $event"
                @update:live-tail="toolbarLiveTail = $event"
            />
        </DemoBlock>

        <div class="grid items-start gap-4 xl:grid-cols-2">
            <DemoBlock title="Heading" description="default and small variants">
                <Heading
                    title="Projects"
                    description="Every source that ships logs into this team."
                />
                <Heading
                    variant="small"
                    title="Danger zone"
                    description="Deleting a project drops its rows from ClickHouse."
                />
            </DemoBlock>

            <DemoBlock
                title="TextLink"
                description="an inertia link in running copy"
            >
                <p class="text-sm">
                    Point your exporter at the ingest endpoint, then head to the
                    <TextLink :href="styleguide()">styleguide</TextLink>
                    to check how a component renders in both modes.
                </p>
            </DemoBlock>

            <DemoBlock
                title="AlertError"
                description="deduplicates the messages it is given"
            >
                <AlertError
                    :errors="[
                        'The project name has already been taken.',
                        'Retention must be at least 1 day.',
                        'The project name has already been taken.',
                    ]"
                />
                <AlertError
                    title="Ingest rejected the batch."
                    :errors="['Payload exceeded the 5 MB limit.']"
                />
            </DemoBlock>

            <DemoBlock
                title="InputError"
                description="the per-field validation message under a control"
            >
                <div class="grid gap-1.5">
                    <Label for="sg-error-field">Project name</Label>
                    <Input
                        id="sg-error-field"
                        model-value="checkout api"
                        aria-invalid="true"
                    />
                    <InputError
                        message="The project name may only contain letters, numbers and dashes."
                    />
                </div>
            </DemoBlock>
        </div>

        <DemoBlock
            title="PlaceholderPattern"
            description="the diagonal hatch that fills a not-yet-built panel"
        >
            <div
                class="relative aspect-[3/1] overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <PlaceholderPattern />
            </div>
        </DemoBlock>
    </SectionShell>
</template>
