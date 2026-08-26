<script setup lang="ts">
import { computed, ref } from 'vue';
import AlertError from '@/components/AlertError.vue';
import ApiKeyCreatedDialog from '@/components/ApiKeyCreatedDialog.vue';
import AppLogo from '@/components/AppLogo.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import AppLogoMark from '@/components/AppLogoMark.vue';
import GetStartedPanel from '@/components/GetStartedPanel.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import LogEntryRow from '@/components/LogEntryRow.vue';
import LogsHistogram from '@/components/LogsHistogram.vue';
import LogsToolbar from '@/components/LogsToolbar.vue';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { DEFAULT_RANGE_PRESET, SEVERITY_LEVELS } from '@/lib/logs';
import { demoHistogram, demoLogEntry } from '@/pages/styleguide/data';
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

/**
 * A miniature of the log page's own filter history, so the Step back and
 * Reset controls demo for real rather than sitting permanently disabled.
 */
type ToolbarSnapshot = {
    project: string | null;
    service: string | null;
    severity: SeverityLevel[];
    search: string | null;
    range: LogRangePreset;
};

const toolbarHistory = ref<ToolbarSnapshot[]>([]);

const toolbarSnapshot = (): ToolbarSnapshot => ({
    project: toolbarProject.value,
    service: toolbarService.value,
    severity: [...toolbarSeverity.value],
    search: toolbarSearch.value,
    range: toolbarRange.value,
});

const recordToolbarChange = (apply: () => void) => {
    toolbarHistory.value = [...toolbarHistory.value, toolbarSnapshot()];
    apply();
};

const toolbarCanReset = computed(
    () =>
        toolbarProject.value !== null ||
        toolbarService.value !== null ||
        toolbarSearch.value !== null ||
        toolbarSeverity.value.length > 0 ||
        toolbarRange.value !== DEFAULT_RANGE_PRESET,
);

const onToolbarStepBack = () => {
    const previous = toolbarHistory.value[toolbarHistory.value.length - 1];

    if (!previous) {
        return;
    }

    toolbarHistory.value = toolbarHistory.value.slice(0, -1);
    toolbarProject.value = previous.project;
    toolbarService.value = previous.service;
    toolbarSeverity.value = [...previous.severity];
    toolbarSearch.value = previous.search;
    toolbarRange.value = previous.range;
};

const onToolbarReset = () =>
    recordToolbarChange(() => {
        toolbarProject.value = null;
        toolbarService.value = null;
        toolbarSeverity.value = [];
        toolbarSearch.value = null;
        toolbarRange.value = DEFAULT_RANGE_PRESET;
    });

const apiKeyDialogOpen = ref(false);

const demoApiKey = {
    name: 'Production collector',
    key: 'bilis_9fZ1qPdK3sWmXbT7vRcYhN2eJgL4uA6oQ8iM0zSr',
};

const histogram = demoHistogram();
const histogramSeverity = ref<SeverityLevel[]>([]);
const histogramZoom = ref<string | null>(null);

const onHistogramZoom = (window: { from: string; to: string }) => {
    histogramZoom.value = `${window.from} → ${window.to}`;
};
</script>

<template>
    <SectionShell
        id="app-components"
        title="App components"
        description="The Bilis-specific pieces that sit on top of the primitives. Everything below is the real component with demo data, so these blocks double as a regression check when the log UI changes."
    >
        <DemoBlock
            title="LogEntryRow"
            description="one row per severity bucket; the error row starts expanded to show the attribute panel. Every row carries a 1px severity hairline on its left edge, so a stack of rows reads as a temperature ribbon before a single line is read — and warn, error and fatal add a resting tint, because those are the only levels that mean something broke."
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
            title="LogsHistogram"
            description="log volume across the window, stacked by severity and coloured from the same --severity-* tokens as the rows beneath it. The server picks the bucket width from the window; clicking a bar zooms the viewer into that bucket."
        >
            <LogsHistogram
                :histogram="histogram"
                :severity="histogramSeverity"
                @zoom="onHistogramZoom"
            />
            <p class="text-xs text-muted-foreground">
                <template v-if="histogramZoom">
                    Zoomed to
                    <span class="font-mono">{{ histogramZoom }}</span>
                </template>
                <template v-else> Click a bar to emit a zoom window. </template>
            </p>
        </DemoBlock>

        <DemoBlock
            title="LogsHistogram — waiting and empty"
            description="the deferred prop has not landed yet, and a window with nothing in it. The empty case draws a dashed baseline rather than collapsing, so the strip never changes height."
        >
            <LogsHistogram :severity="[]" />
            <LogsHistogram
                :severity="[]"
                :histogram="{
                    buckets: [],
                    intervalSeconds: 60,
                    total: 0,
                    unavailable: false,
                }"
            />
        </DemoBlock>

        <DemoBlock
            title="GetStartedPanel — no projects"
            description="contextual onboarding, step one. A team with nowhere to send logs is told to make somewhere first; no snippets appear until a project exists, because a curl line without a key behind it is a dead end."
        >
            <GetStartedPanel
                stage="no-projects"
                team-slug="bilis"
                origin="https://bilis.app"
            />
        </DemoBlock>

        <DemoBlock
            title="GetStartedPanel — no logs yet"
            description="step two: the project exists and has never received a line. The ingest host is read off the current origin so a copied snippet always points at this install, the key stays a placeholder (Bilis only ever shows a key once, at creation), and the logs page polls behind this panel so it flips to the stream on its own."
        >
            <GetStartedPanel
                stage="no-logs"
                :projects="demoProjects"
                team-slug="bilis"
                origin="https://bilis.app"
            />
        </DemoBlock>

        <DemoBlock
            title="LogsToolbar"
            description="wired to local refs, so the controls move but nothing is fetched. Three tiers, hairline-separated: search plus live tail on top because those are the two ways you reach a line, then scope and window plus the history controls, then severity. Step back walks the filter history the log page keeps in local state; Reset returns every filter to its default, and both go disabled rather than disappearing when there is nothing to do. Warn, error and fatal are toggled on — active severity chips fill with the secondary tint, a solid dot and semibold copy, inactive ones stay outlined and muted with a faded dot."
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
                :can-step-back="toolbarHistory.length > 0"
                :can-reset="toolbarCanReset"
                @step-back="onToolbarStepBack"
                @reset="onToolbarReset"
                @update:project="
                    recordToolbarChange(() => (toolbarProject = $event))
                "
                @update:service="
                    recordToolbarChange(() => (toolbarService = $event))
                "
                @update:severity="
                    recordToolbarChange(() => (toolbarSeverity = $event))
                "
                @update:search="
                    recordToolbarChange(() => (toolbarSearch = $event))
                "
                @update:range="
                    recordToolbarChange(() => (toolbarRange = $event))
                "
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
            title="AppLogo"
            description="the sidebar wordmark: the square mark next to the app name"
        >
            <div class="flex items-center">
                <AppLogo />
            </div>
        </DemoBlock>

        <DemoBlock
            title="AppLogoMark"
            description="the full brand mark — the fish trailing its three speed stripes. Use wherever the layout gives the logo room to run horizontally"
        >
            <AppLogoMark class="h-16 w-auto" />
        </DemoBlock>

        <DemoBlock
            title="AppLogoIcon"
            description="the same mark turned 45deg so it squares off — for tiles, the sidebar and the favicon. Body and detail colours invert in dark mode via --logo-body / --logo-detail"
        >
            <div class="flex items-end gap-6">
                <AppLogoIcon class="size-16" />
                <AppLogoIcon class="size-8" />
                <AppLogoIcon class="size-5" />
                <div
                    class="flex size-16 items-center justify-center rounded-xl bg-espresso [--logo-body:var(--color-cream)] [--logo-detail:var(--color-espresso)]"
                >
                    <AppLogoIcon class="size-12" />
                </div>
            </div>
        </DemoBlock>

        <DemoBlock
            title="ApiKeyCreatedDialog"
            description="the one and only time a plaintext key is on screen: a monospace field, a copy button that flips to a check, and a warning that Bilis stores nothing but the hash"
        >
            <Button variant="secondary" @click="apiKeyDialogOpen = true">
                Show the dialog
            </Button>

            <ApiKeyCreatedDialog
                v-model:open="apiKeyDialogOpen"
                :api-key="demoApiKey"
            />
        </DemoBlock>

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
