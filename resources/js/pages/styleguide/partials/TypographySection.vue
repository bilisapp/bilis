<script setup lang="ts">
import { SEVERITY_DOT_CLASS, SEVERITY_TEXT_CLASS } from '@/lib/logs';
import { cn } from '@/lib/utils';
import DemoBlock from './DemoBlock.vue';
</script>

<template>
    <div class="space-y-6">
        <DemoBlock
            title="Type ramp — named tokens"
            description="One scale for the app and the public site, defined in app.css @theme: text-display (public hero, once a page), text-headline (public section), text-title (page title, KPI figure), text-lead, text-body, text-caption and text-label. Display and headline carry their own tight tracking (tracking-display, −0.04em); the Paper 'Design system — Bilis' board is the same ramp."
        >
            <div class="divide-y">
                <div
                    v-for="step in [
                        {
                            token: 'text-display',
                            note: '72/72 · −0.04em · public hero',
                            cls: 'text-display font-semibold',
                            sample: 'Read production',
                        },
                        {
                            token: 'text-headline',
                            note: '48/52 · −0.04em · public section',
                            cls: 'text-headline font-semibold',
                            sample: 'Keep the data.',
                        },
                        {
                            token: 'text-title',
                            note: '30/36 · −0.025em · page title',
                            cls: 'text-title font-semibold',
                            sample: 'Issues',
                        },
                        {
                            token: 'text-lead',
                            note: '16/26 · section heading, lead',
                            cls: 'text-lead font-semibold tracking-tight',
                            sample: 'Top errors',
                        },
                        {
                            token: 'text-body',
                            note: '14/20 · copy, controls',
                            cls: 'text-body',
                            sample: '5 unresolved · last 7 days · 1 regressed',
                        },
                        {
                            token: 'text-caption',
                            note: '12/16 · mono data',
                            cls: 'text-caption font-mono',
                            sample: '15:20:31.402  ERROR  PaymentDeclinedError',
                        },
                        {
                            token: 'text-label',
                            note: '11/16 · badges, kbd',
                            cls: 'text-label font-medium',
                            sample: 'Regressed',
                        },
                    ]"
                    :key="step.token"
                    class="flex flex-col gap-1 py-4 sm:flex-row sm:items-baseline sm:gap-8"
                >
                    <span
                        class="w-56 shrink-0 font-mono text-xs text-muted-foreground"
                        >{{ step.token }}<br />{{ step.note }}</span
                    >
                    <span :class="cn('min-w-0 truncate', step.cls)">{{
                        step.sample
                    }}</span>
                </div>
            </div>
        </DemoBlock>

        <DemoBlock
            title="Named utilities — eyebrow, column-header, kbd"
            description="Three pieces of the vocabulary with a name of their own, so a class list says what a thing is. Vue components (PageHeader, every list header, KeyHint) and the Blade components under resources/views/components/ui use the same three."
        >
            <div class="flex flex-col gap-5">
                <p class="eyebrow">All projects / All services</p>
                <div
                    class="grid grid-cols-[minmax(0,1fr)_6rem_6rem] gap-6 border-y column-header px-4 py-2.5"
                >
                    <span>Issue</span><span class="text-right">Events</span
                    ><span class="text-right">Last seen</span>
                </div>
                <p
                    class="flex items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <kbd class="kbd text-foreground/70">j</kbd>
                    <kbd class="kbd text-foreground/70">k</kbd> move
                    <kbd class="ml-3 kbd text-foreground/70">/</kbd> search
                </p>
            </div>
        </DemoBlock>

        <DemoBlock
            title="Layout tokens"
            description="px-gutter (2.5rem) is the one left edge every band of a page shares — the app pages write it as px-4 md:px-10; py-section (6rem) is the breath between public sections; max-w-content (70rem) is the public page column."
        >
            <div class="flex flex-col gap-2 font-mono text-xs">
                <div class="flex items-center gap-3">
                    <span
                        class="h-3 w-gutter shrink-0 rounded-sm bg-foreground/70"
                    />
                    --spacing-gutter · 2.5rem
                </div>
                <div class="flex items-center gap-3">
                    <span
                        class="h-3 w-section shrink-0 rounded-sm bg-foreground/70"
                    />
                    --spacing-section · 6rem
                </div>
                <div class="flex items-center gap-3">
                    <span
                        class="h-3 w-full max-w-content rounded-sm bg-foreground/20"
                    />
                </div>
                <span class="text-muted-foreground"
                    >--container-content · 70rem</span
                >
            </div>
        </DemoBlock>

        <div class="grid gap-4 lg:grid-cols-2">
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
    </div>
</template>
