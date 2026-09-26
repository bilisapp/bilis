<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { computed, ref } from 'vue';
import {
    ERROR_MONITORING_TABS,
    errorMonitoringSnippets,
} from '@/lib/errorMonitoring';
import type { ErrorMonitoringTab } from '@/lib/errorMonitoring';
import { cn } from '@/lib/utils';

type Props = {
    /** The project's DSN, when it has a key; otherwise a placeholder is shown. */
    dsn?: string | null;
    /** The service name the SDK snippets tag their events with. */
    service?: string;
    /** Overrides the Bilis origin, so the styleguide can show a stable host. */
    origin?: string;
};

const props = withDefaults(defineProps<Props>(), {
    dsn: null,
    service: undefined,
    origin: undefined,
});

const tab = ref<ErrorMonitoringTab>('workers-otlp');

const HINTS: Record<ErrorMonitoringTab, string> = {
    'workers-otlp':
        "No code change: Cloudflare exports console output, the runtime’s own logs (uncaught errors included) and request spans over OTLP/HTTP JSON, straight into this project's logs and traces. Needs Workers Paid; replace the key placeholder with one of this project's keys.",
    'workers-sdk':
        'Adds full stack traces with breadcrumbs and request context to each exception. Works on any Workers plan; pair it with the OTLP export for the logs and spans around each error.',
    node: 'Any Sentry server SDK works as it is — point its DSN here. Exceptions land as ERROR logs, searchable by exception.type.',
    browser:
        'Browser SDKs post from your visitors’ pages, so their origin has to be listed under Browser origins first.',
    python: 'Django, Flask and FastAPI integrations are picked up automatically once the SDK is initialised.',
    laravel:
        'The Laravel SDK reports what the exception handler sees. Bilis’s own log channel remains the way to ship ordinary log lines.',
};

const snippets = computed(() =>
    errorMonitoringSnippets({
        origin:
            props.origin ??
            (typeof window === 'undefined' ? '' : window.location.origin),
        dsn: props.dsn,
        service: props.service,
    }),
);

const { copy, copied } = useClipboard({ copiedDuring: 1_500, legacy: true });
</script>

<template>
    <div class="space-y-3" data-test="error-monitoring-setup">
        <div
            class="flex flex-wrap gap-1 border-b"
            role="tablist"
            aria-label="Error monitoring setup"
        >
            <button
                v-for="item in ERROR_MONITORING_TABS"
                :key="item.id"
                type="button"
                role="tab"
                :aria-selected="tab === item.id"
                :data-test="`error-monitoring-tab-${item.id}`"
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
                data-test="error-monitoring-snippet"
            ><code>{{ snippets[tab] }}</code></pre>

            <button
                type="button"
                class="absolute top-2 right-2 rounded-md border border-input bg-card p-2 text-muted-foreground transition-colors hover:text-foreground"
                data-test="error-monitoring-copy"
                :aria-label="copied ? 'Copied' : 'Copy snippet'"
                @click="copy(snippets[tab])"
            >
                <Check v-if="copied" class="size-4 text-foreground" />
                <Copy v-else class="size-4" />
            </button>
        </div>

        <p class="text-xs text-muted-foreground">
            <a
                v-if="tab.startsWith('workers')"
                href="/docs/ingestion/cloudflare-workers"
                class="underline underline-offset-4 hover:text-foreground"
                data-test="error-monitoring-docs"
                >Cloudflare Workers guide</a
            >
            <a
                v-else
                href="/docs/ingestion/sentry"
                class="underline underline-offset-4 hover:text-foreground"
                data-test="error-monitoring-docs"
                >What is stored, and what is dropped</a
            >
        </p>
    </div>
</template>
