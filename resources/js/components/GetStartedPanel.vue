<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Check,
    ChevronDown,
    Copy,
    FolderKanban,
    KeyRound,
    Plus,
} from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import CreateProjectModal from '@/components/CreateProjectModal.vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { index as projectsIndex, show as projectShow } from '@/routes/projects';
import type { LogOnboardingStage, LogProject } from '@/types';
import bilisHandlerSource from '../../../app/Logging/BilisHandler.php?raw';
import bilisLoggerSource from '../../../app/Logging/BilisLogger.php?raw';

const props = withDefaults(
    defineProps<{
        stage: LogOnboardingStage;
        projects?: LogProject[];
        teamSlug: string;
        /** Overridable so the styleguide can show a stable, fake host. */
        origin?: string;
    }>(),
    { projects: () => [], origin: undefined },
);

/**
 * The ingest host, taken from where the user actually is. A copied snippet
 * that points at someone else's install is worse than no snippet at all.
 */
const baseUrl = computed(
    () =>
        props.origin ??
        (typeof window === 'undefined' ? '' : window.location.origin),
);

const selectedSlug = ref<string>(props.projects[0]?.slug ?? '');

watch(
    () => props.projects,
    (projects) => {
        if (!projects.some((project) => project.slug === selectedSlug.value)) {
            selectedSlug.value = projects[0]?.slug ?? '';
        }
    },
);

const selectedProject = computed<LogProject | null>(
    () =>
        props.projects.find((project) => project.slug === selectedSlug.value) ??
        null,
);

const TABS = [
    { id: 'curl', label: 'curl' },
    { id: 'otel', label: 'OpenTelemetry' },
    { id: 'laravel', label: 'Laravel' },
] as const;

type TabId = (typeof TABS)[number]['id'];

const tab = ref<TabId>('curl');

const HINTS: Record<TabId, string> = {
    curl: 'The fastest proof the pipe works: one line, straight into the simple JSON endpoint.',
    otel: 'Any OTLP exporter — Go, Python, Node, the Collector — needs nothing but these three variables. Bilis speaks OTLP over HTTP/JSON.',
    laravel:
        'A first-party package is on the way. Until then, copy the channel config plus the two classes below — the exact, tested code this Bilis instance ships with.',
};

const snippets = computed<Record<TabId, string>>(() => {
    const url = baseUrl.value;

    return {
        curl: `curl -X POST ${url}/api/v1/ingest \\
  -H "Authorization: Bearer <YOUR_API_KEY>" \\
  -H "Content-Type: application/json" \\
  -d '{"message":"Hello from curl","level":"info","service":"checkout"}'`,
        otel: `OTEL_EXPORTER_OTLP_PROTOCOL=http/json
OTEL_EXPORTER_OTLP_ENDPOINT=${url}/api/v1
OTEL_EXPORTER_OTLP_HEADERS="Authorization=Bearer <YOUR_API_KEY>"`,
        laravel: `// .env
BILIS_ENDPOINT=${url}/api/v1/ingest
BILIS_API_KEY=<YOUR_API_KEY>
LOG_STACK=single,bilis

// config/logging.php — add the channel
'bilis' => [
    'driver' => 'custom',
    'via' => App\\Logging\\BilisLogger::class,
    'endpoint' => env('BILIS_ENDPOINT'),
    'api_key' => env('BILIS_API_KEY'),
    'level' => env('BILIS_LOG_LEVEL', 'debug'),
],

// Then drop the two classes below into app/Logging/ — done.
// Records buffer in memory and ship as one batched request after
// the response; a dead or unreachable Bilis never breaks your app.`,
    };
});

/**
 * The real shipper source, inlined at build time. What the reader copies is
 * the exact code this instance runs — it cannot drift from reality.
 */
const SHIPPER_FILES = [
    { path: 'app/Logging/BilisLogger.php', source: bilisLoggerSource },
    { path: 'app/Logging/BilisHandler.php', source: bilisHandlerSource },
] as const;

const {
    copy,
    copied,
    text: copiedText,
} = useClipboard({
    copiedDuring: 1_500,
    // navigator.clipboard needs a secure context; self-hosted installs often
    // run plain http, so fall back to the legacy execCommand path there.
    legacy: true,
});
</script>

<template>
    <div
        class="flex min-h-0 flex-1 flex-col overflow-y-auto rounded-xl border bg-card"
        data-test="logs-get-started"
        :data-stage="stage"
    >
        <!--
          Step one: there is nothing to send logs to yet. No exporter talk here
          — a snippet without a project behind it is a dead end.
        -->
        <div
            v-if="stage === 'no-projects'"
            class="flex flex-1 flex-col items-center justify-center gap-4 px-6 py-16 text-center"
            data-test="get-started-no-projects"
        >
            <span
                class="flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground"
            >
                <FolderKanban class="size-5" />
            </span>

            <div class="space-y-1">
                <p class="text-base font-semibold">Create a project first</p>
                <p class="max-w-sm text-sm text-balance text-muted-foreground">
                    A project is one application's stream of logs, and it owns
                    the API keys your collector authenticates with. Nothing can
                    land here until one exists.
                </p>
            </div>

            <div class="flex flex-wrap items-center justify-center gap-2">
                <CreateProjectModal :team-slug="teamSlug">
                    <Button data-test="get-started-create-project">
                        <Plus /> Create your first project
                    </Button>
                </CreateProjectModal>

                <Button variant="ghost" as-child>
                    <Link :href="projectsIndex(teamSlug)"> All projects </Link>
                </Button>
            </div>
        </div>

        <!--
          Step two: the project exists and has never seen a line. Everything on
          screen is now about getting the first one through.
        -->
        <div v-else class="flex flex-col" data-test="get-started-no-logs">
            <header
                class="flex flex-wrap items-end justify-between gap-4 border-b p-5"
            >
                <div class="space-y-1">
                    <p class="text-base font-semibold">Send your first log</p>
                    <p class="max-w-prose text-sm text-muted-foreground">
                        Point anything that can make an HTTP request at the
                        ingest endpoint. The API key decides which project the
                        line lands in — Bilis never reads a project from the
                        payload.
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <Select v-if="projects.length > 1" v-model="selectedSlug">
                        <SelectTrigger
                            size="sm"
                            class="w-44"
                            data-test="get-started-project"
                        >
                            <SelectValue placeholder="Project" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="project in projects"
                                :key="project.slug"
                                :value="project.slug"
                            >
                                {{ project.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Button
                        v-if="selectedProject"
                        variant="outline"
                        size="sm"
                        as-child
                        data-test="get-started-manage-keys"
                    >
                        <Link
                            :href="
                                projectShow([teamSlug, selectedProject.slug])
                            "
                        >
                            <KeyRound /> Manage API keys
                        </Link>
                    </Button>
                </div>
            </header>

            <div class="space-y-4 p-5">
                <p class="text-sm text-muted-foreground">
                    Replace
                    <code
                        class="rounded bg-muted px-1 py-0.5 font-mono text-xs text-foreground"
                        >&lt;YOUR_API_KEY&gt;</code
                    >
                    with a key from
                    <span class="font-medium text-foreground">{{
                        selectedProject?.name ?? 'your project'
                    }}</span>
                    — it is shown once, when you create it, and only its hash is
                    stored.
                </p>

                <div
                    class="flex flex-wrap gap-1 border-b"
                    role="tablist"
                    aria-label="Setup snippets"
                >
                    <button
                        v-for="item in TABS"
                        :key="item.id"
                        type="button"
                        role="tab"
                        :aria-selected="tab === item.id"
                        :data-test="`get-started-tab-${item.id}`"
                        :class="
                            cn(
                                '-mb-px border-b-2 px-3 py-2 text-sm font-medium transition-colors',
                                tab === item.id
                                    ? 'border-primary text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )
                        "
                        @click="tab = item.id"
                    >
                        {{ item.label }}
                    </button>
                </div>

                <p class="text-sm text-muted-foreground">{{ HINTS[tab] }}</p>

                <div
                    class="relative overflow-hidden rounded-lg border border-input bg-muted/40"
                    role="tabpanel"
                >
                    <pre
                        class="overflow-x-auto p-4 pr-14 font-mono text-xs leading-relaxed text-foreground"
                        data-test="get-started-snippet"
                    ><code>{{ snippets[tab] }}</code></pre>

                    <button
                        type="button"
                        class="absolute top-2 right-2 rounded-md border border-input bg-card p-2 text-muted-foreground transition-colors hover:text-foreground"
                        data-test="get-started-copy"
                        :aria-label="copied ? 'Copied' : 'Copy snippet'"
                        @click="copy(snippets[tab])"
                    >
                        <Check v-if="copied" class="size-4 text-teal" />
                        <Copy v-else class="size-4" />
                    </button>
                </div>

                <div v-if="tab === 'laravel'" class="space-y-2">
                    <Collapsible
                        v-for="file in SHIPPER_FILES"
                        :key="file.path"
                        class="overflow-hidden rounded-lg border border-input"
                    >
                        <CollapsibleTrigger
                            class="flex w-full items-center justify-between gap-2 bg-muted/40 px-4 py-2.5 text-left font-mono text-xs font-medium transition-colors hover:bg-muted/70"
                            :data-test="`get-started-file-${file.path}`"
                        >
                            {{ file.path }}
                            <ChevronDown class="size-4 text-muted-foreground" />
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <div class="relative border-t border-input">
                                <pre
                                    class="max-h-96 overflow-auto p-4 pr-14 font-mono text-xs leading-relaxed text-foreground"
                                ><code>{{ file.source }}</code></pre>
                                <button
                                    type="button"
                                    class="absolute top-2 right-2 rounded-md border border-input bg-card p-2 text-muted-foreground transition-colors hover:text-foreground"
                                    :aria-label="
                                        copied && copiedText === file.source
                                            ? 'Copied'
                                            : `Copy ${file.path}`
                                    "
                                    @click="copy(file.source)"
                                >
                                    <Check
                                        v-if="
                                            copied && copiedText === file.source
                                        "
                                        class="size-4 text-teal"
                                    />
                                    <Copy v-else class="size-4" />
                                </button>
                            </div>
                        </CollapsibleContent>
                    </Collapsible>
                </div>

                <!--
                  The page polls while this is on screen, so the reader can sit
                  here and watch it flip rather than reaching for reload.
                -->
                <p
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                    data-test="get-started-waiting"
                >
                    <span class="relative flex size-2">
                        <span
                            class="absolute inline-flex size-2 animate-ping rounded-full bg-severity-info opacity-60 motion-reduce:hidden"
                        />
                        <span
                            class="relative inline-flex size-2 rounded-full bg-severity-info"
                        />
                    </span>
                    Waiting for your first log — this page switches to the
                    stream the moment one arrives.
                </p>
            </div>
        </div>
    </div>
</template>
