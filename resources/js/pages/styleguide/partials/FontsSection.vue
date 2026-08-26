<script setup lang="ts">
import DemoBlock from './DemoBlock.vue';
import SectionShell from './SectionShell.vue';

interface FontSpecimen {
    name: string;
    fontClass: string;
    status: string;
}

const sansSpecimens: FontSpecimen[] = [
    {
        name: 'Instrument Sans',
        fontClass: 'font-sans',
        status: 'current',
    },
    {
        name: 'IBM Plex Sans',
        fontClass: 'font-plex-sans',
        status: 'candidate',
    },
];

const monoSpecimens: FontSpecimen[] = [
    {
        name: 'System mono stack',
        fontClass: 'font-mono',
        status: 'current',
    },
    {
        name: 'IBM Plex Mono',
        fontClass: 'font-plex-mono',
        status: 'candidate',
    },
];

const logLines = [
    {
        timestamp: '2026-08-26 14:03:11.482',
        severity: 'INFO',
        severityClass: 'text-severity-info',
        service: 'checkout-api',
        body: 'Order 84213 accepted for team acme-inc (idempotency_key=ord_1lI0O)',
    },
    {
        timestamp: '2026-08-26 14:03:12.019',
        severity: 'ERROR',
        severityClass: 'text-severity-error',
        service: 'edge-proxy',
        body: 'upstream timeout after 3000ms: POST /api/v1/logs (trace_id=a3f8c02e)',
    },
];
</script>

<template>
    <SectionShell
        id="fonts"
        title="Fonts"
        description="Side-by-side specimens of the current faces against the IBM Plex candidates, loaded via Bunny in vite.config.ts for evaluation only. To adopt one, point --font-sans (or add --font-mono) at it in resources/css/app.css and drop the loser from the vite fonts list."
    >
        <div class="grid gap-4 lg:grid-cols-2">
            <DemoBlock
                v-for="specimen in sansSpecimens"
                :key="specimen.name"
                :title="`${specimen.name} — ${specimen.status}`"
                :description="`UI face · utility ${specimen.fontClass}`"
            >
                <div :class="specimen.fontClass" class="space-y-3">
                    <h3 class="text-2xl font-semibold tracking-tight">
                        Ship logs, not infrastructure
                    </h3>
                    <p class="text-sm">
                        Bilis stores structured logs in ClickHouse and gives
                        your team a fast, searchable view of them. Severity,
                        service, and full-text filters over the last 24 hours.
                    </p>
                    <p class="text-sm font-medium">
                        Medium — toolbar labels and buttons: Live tail · Load
                        older logs · Copy API key
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Showing 1–50 of 12,481 rows · 0O o · 1lI| · 3.14159 ·
                        {}[]()
                    </p>
                </div>
            </DemoBlock>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <DemoBlock
                v-for="specimen in monoSpecimens"
                :key="specimen.name"
                :title="`${specimen.name} — ${specimen.status}`"
                :description="`Log data face · utility ${specimen.fontClass}`"
            >
                <div
                    :class="specimen.fontClass"
                    class="space-y-1 overflow-x-auto text-xs"
                >
                    <div
                        v-for="line in logLines"
                        :key="line.body"
                        class="flex items-baseline gap-3 whitespace-nowrap"
                    >
                        <span class="text-muted-foreground tabular-nums">{{
                            line.timestamp
                        }}</span>
                        <span
                            :class="line.severityClass"
                            class="w-12 font-medium"
                            >{{ line.severity }}</span
                        >
                        <span class="text-muted-foreground">{{
                            line.service
                        }}</span>
                        <span>{{ line.body }}</span>
                    </div>
                    <div class="pt-2 text-muted-foreground">
                        0O o 1lI| `'" {}[]() &lt;=&gt; -&gt; !== 0x1F a3f8c02e
                    </div>
                </div>
            </DemoBlock>
        </div>
    </SectionShell>
</template>
