<script setup lang="ts">
import { computed, ref } from 'vue';
import AlertError from '@/components/AlertError.vue';
import ApiKeyCreatedDialog from '@/components/ApiKeyCreatedDialog.vue';
import AppLogo from '@/components/AppLogo.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import AppLogoMark from '@/components/AppLogoMark.vue';
import AskAiMenu from '@/components/AskAiMenu.vue';
import AutofixUpsellModal from '@/components/AutofixUpsellModal.vue';
import GetStartedPanel from '@/components/GetStartedPanel.vue';
import GitHubLoginButton from '@/components/GitHubLoginButton.vue';
import Heading from '@/components/Heading.vue';
import IngestRateCard from '@/components/IngestRateCard.vue';
import InputError from '@/components/InputError.vue';
import LogEntryRow from '@/components/LogEntryRow.vue';
import LogRowActions from '@/components/LogRowActions.vue';
import LogsHistogram from '@/components/LogsHistogram.vue';
import LogsShortcutsDialog from '@/components/LogsShortcutsDialog.vue';
import LogsToolbar from '@/components/LogsToolbar.vue';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import RunAutofixModal from '@/components/RunAutofixModal.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { DEFAULT_RANGE_PRESET, SEVERITY_LEVELS } from '@/lib/logs';
import {
    demoHistogram,
    demoIngestRate,
    demoIngestRateDisabled,
    demoLogEntry,
} from '@/pages/styleguide/data';
import { styleguide } from '@/routes';
import type {
    LogAutofixTarget,
    LogProject,
    LogRangePreset,
    SeverityLevel,
} from '@/types';
import DemoBlock from './DemoBlock.vue';
import SectionShell from './SectionShell.vue';

const demoEntries = SEVERITY_LEVELS.map((level, index) => ({
    level,
    entry: demoLogEntry(level, index),
}));

const expandedLevel = ref<SeverityLevel | null>('error');

/**
 * The two answers a row can get when somebody asks it to be fixed: a
 * repository responsible for the line's service, or none yet.
 */
const demoAutofixConnected: LogAutofixTarget = {
    project: {
        slug: 'checkout',
        name: 'Checkout',
        catchAll: 'bilisapp/checkout',
        services: {},
    },
    repository: 'bilisapp/checkout',
};

const demoAutofixUnconnected: LogAutofixTarget = {
    project: {
        slug: 'checkout',
        name: 'Checkout',
        catchAll: null,
        services: {},
    },
    repository: null,
};

const demoErrorEntry = demoLogEntry('error', 2);

const toggleExpanded = (level: SeverityLevel) => {
    expandedLevel.value = expandedLevel.value === level ? null : level;
};

const demoProjects: LogProject[] = [
    { name: 'checkout-api', slug: 'checkout-api' },
    { name: 'bilis-ingest', slug: 'bilis-ingest' },
];

const demoServices: string[] = [
    'checkout-web',
    'checkout-worker',
    'otlp-collector',
    'payments-gateway',
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

const shortcutsOpen = ref(false);

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
            title="LogRowActions"
            description="what one line can be handed to without leaving the stream: copy the line as UTC plain text, ask a general-purpose assistant about it, or hand it to the autofix agent. In a log row it floats above the line rather than taking a column of it — reserving the width would shorten every line on the page to make room for a control that is invisible most of the time — and it is untouchable while hidden, so it never swallows a click aimed at the line beneath. Shown here in the flow, since positioning is the caller's business. The fix button is present in both states — with a repository responsible for the line's service it opens the run dialog, without one it opens the case for connecting a repository. A button that disappears teaches nobody the feature exists."
        >
            <div class="flex flex-wrap items-center gap-6">
                <LogRowActions
                    :entry="demoErrorEntry"
                    team-slug="bilis"
                    :autofix="demoAutofixConnected"
                />
                <LogRowActions
                    :entry="demoErrorEntry"
                    team-slug="bilis"
                    :autofix="demoAutofixUnconnected"
                />
            </div>
        </DemoBlock>

        <DemoBlock
            title="RunAutofixModal"
            description="the step between the click and the run, because a run costs money and opens a pull request. It quotes the line back and names the repository the server resolved for it — the only place the reader sees that answer before agreeing to it — and offers the assistant route in the same footer. The scan's five-occurrence floor does not apply here: it exists to stop an unattended loop spending on noise, and the person in front of this dialog has already made that call. Submitting from the styleguide posts to a team slug that does not exist."
        >
            <RunAutofixModal
                :entry="demoErrorEntry"
                team-slug="bilis"
                repository="bilisapp/checkout"
            >
                <Button variant="secondary">Show the run dialog</Button>
            </RunAutofixModal>
        </DemoBlock>

        <DemoBlock
            title="AskAiMenu"
            description="the escape hatch for every line autofix cannot take: no repository connected, no model key, or simply not worth an agent run. The prompt is built from the line rather than typed, so what reaches the assistant carries the timestamp, the service and the attributes — the parts people forget to paste and then get a worse answer for. Gemini has no prefill parameter, so its item copies the prompt and says so."
        >
            <AskAiMenu :entry="demoErrorEntry" align="start" />
        </DemoBlock>

        <DemoBlock
            title="AutofixUpsellModal"
            description="what the fix button says when nothing can fix it yet. It answers the question the click was asking — here is what a connected repository would have done with this line, here is where to connect one — and offers the assistant route as a real answer rather than a consolation. It opens on a click, never on its own."
        >
            <AutofixUpsellModal
                :entry="demoErrorEntry"
                team-slug="bilis"
                project-slug="checkout"
                project-name="Checkout"
            >
                <Button variant="secondary">Show the case</Button>
            </AutofixUpsellModal>
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
            title="LogsShortcutsDialog"
            description="the sheet behind ? in the log viewer. The stream answers to the same keys as less — j/k to walk lines, o to expand, / to search, g/G for the ends — because the people who self-host a log viewer already have that muscle memory. Every shortcut has a pointer equivalent; none of them is the only way to do anything."
        >
            <Button variant="secondary" @click="shortcutsOpen = true">
                Show the sheet
            </Button>

            <LogsShortcutsDialog v-model:open="shortcutsOpen" />
        </DemoBlock>

        <DemoBlock
            title="LogsToolbar"
            description="wired to local refs, so the controls move but nothing is fetched. Three tiers, hairline-separated: search plus live tail on top because those are the two ways you reach a line, then scope and window plus the history controls, then severity. Step back walks the filter history the log page keeps in local state; Reset returns every filter to its default, and both go disabled rather than disappearing when there is nothing to do. The service picker is filled from the services the projects in scope have actually logged, so nobody has to remember a name; it defaults to all of them. Warn, error and fatal are toggled on — active severity chips fill with the secondary tint, a solid dot and semibold copy, inactive ones stay outlined and muted with a faded dot."
        >
            <LogsToolbar
                :projects="demoProjects"
                :services="demoServices"
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

        <DemoBlock
            title="IngestRateCard"
            description="the dashboard's live throughput card: what each API key has spent of its per-minute ingest budget. The counter is the throttle's own rolling minute, so the card says &quot;this minute&quot; and never charts it. A resting bar is chart data (teal); at 80% it takes the warn hue and at the ceiling the error hue, because a key at its limit is being answered with 429s."
        >
            <IngestRateCard :usage="demoIngestRate" />
        </DemoBlock>

        <DemoBlock
            title="IngestRateCard — limiter off, and no keys yet"
            description="BILIS_INGEST_RATE_LIMIT=0 turns the limiter off entirely: the keys are still listed, but nothing is counted and no bar can fill. A team whose projects have no keys gets the third state instead."
        >
            <IngestRateCard :usage="demoIngestRateDisabled" />
            <IngestRateCard
                :usage="{ limit: 1200, disabled: false, keys: [] }"
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
                title="GitHubLoginButton"
                description="the OAuth entry point on the login and register pages. An outline button like any other — the GitHub mark is drawn in currentColor, so the chrome stays achromatic. The optional separator divides it from the email form beneath."
            >
                <GitHubLoginButton />
                <GitHubLoginButton
                    label="Continue with GitHub"
                    separator="Or continue with email"
                />
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
            description="the same mark turned 45deg so it squares off — for tiles, the sidebar and the favicon. The three tail stripes are the origin of the product's data palette, so the mark keeps them; body and detail follow the surface via --logo-body / --logo-detail, as the inverted tile on the right shows"
        >
            <div class="flex items-end gap-6">
                <AppLogoIcon class="size-16" />
                <AppLogoIcon class="size-8" />
                <AppLogoIcon class="size-5" />
                <div
                    class="flex size-16 items-center justify-center rounded-lg bg-foreground [--logo-detail:var(--foreground)]"
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
