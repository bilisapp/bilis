<script setup lang="ts">
import { SEVERITY_DOT_CLASS, SEVERITY_TEXT_CLASS } from '@/lib/logs';
import { cn } from '@/lib/utils';
import DemoBlock from './DemoBlock.vue';
import SectionShell from './SectionShell.vue';
</script>

<template>
    <SectionShell
        id="typography"
        title="Typography"
        description="Geist for everything the interface says, Geist Mono for everything a machine wrote. The split is the whole type system: if it came out of a log — a body, a timestamp, an id, an attribute pair — it is monospace; if the interface is talking about that data, it is Geist. Headings stay tight; body copy sits at 14px and support copy at 12px, so a dense log table and its surrounding chrome share one rhythm."
    >
        <div class="grid gap-4 lg:grid-cols-2">
            <DemoBlock
                title="Heading scale"
                description="text-3xl / text-xl / text-lg / text-base, all font-semibold tracking-tight"
            >
                <h1 class="text-3xl font-semibold tracking-tight">
                    Ship logs, not infrastructure
                </h1>
                <h2 class="text-xl font-semibold tracking-tight">
                    Projects and API keys
                </h2>
                <h3 class="text-lg font-semibold tracking-tight">
                    Ingest endpoints
                </h3>
                <h4 class="text-base font-medium">Retention policy</h4>
            </DemoBlock>

            <DemoBlock
                title="Body and support"
                description="text-sm body, text-xs support, muted-foreground for metadata"
            >
                <p class="text-sm">
                    Bilis stores structured logs in ClickHouse and gives your
                    team a fast, searchable view of them. Point any
                    OTLP-compatible exporter at your ingest endpoint and the
                    rows show up here within a second.
                </p>
                <p class="text-xs">
                    Small copy is used for helper text under form fields and for
                    toolbar labels.
                </p>
                <p class="text-sm text-muted-foreground">
                    Muted copy carries metadata: timestamps, row counts, and the
                    "no results in this window" empty states.
                </p>
                <p class="text-xs text-muted-foreground">
                    Showing 1–50 of 12,481 rows · last 15 minutes
                </p>
            </DemoBlock>
        </div>

        <DemoBlock
            title="Monospace log row"
            description="Geist Mono at text-xs with tabular-nums timestamps — the exact treatment LogEntryRow uses"
        >
            <div class="overflow-x-auto rounded-md border">
                <div
                    v-for="row in [
                        {
                            level: 'info' as const,
                            time: '2026-08-26 09:14:02.118',
                            service: 'checkout-api',
                            body: 'POST /api/v1/logs 202 project=checkout-api batch=128',
                        },
                        {
                            level: 'warn' as const,
                            time: '2026-08-26 09:14:02.443',
                            service: 'bilis-ingest',
                            body: 'ingest batch throttled, retrying in 250ms queue_depth=4096',
                        },
                        {
                            level: 'error' as const,
                            time: '2026-08-26 09:14:03.007',
                            service: 'bilis-ingest',
                            body: 'failed to flush batch to ClickHouse: connection reset by peer',
                        },
                    ]"
                    :key="row.time"
                    class="flex items-start gap-3 border-b px-3 py-1.5 font-mono text-xs last:border-b-0"
                >
                    <span class="shrink-0 text-muted-foreground tabular-nums">
                        {{ row.time }}
                    </span>
                    <span
                        :class="
                            cn(
                                'inline-flex w-16 shrink-0 items-center gap-1.5 font-semibold uppercase',
                                SEVERITY_TEXT_CLASS[row.level],
                            )
                        "
                    >
                        <span
                            :class="
                                cn(
                                    'size-2 shrink-0 rounded-full',
                                    SEVERITY_DOT_CLASS[row.level],
                                )
                            "
                        />
                        {{ row.level }}
                    </span>
                    <span class="w-40 shrink-0 truncate text-muted-foreground">
                        {{ row.service }}
                    </span>
                    <span class="min-w-0 flex-1 truncate">{{ row.body }}</span>
                </div>
            </div>
        </DemoBlock>
    </SectionShell>
</template>
