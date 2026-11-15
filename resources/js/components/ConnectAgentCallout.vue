<script setup lang="ts">
import { Bot } from '@lucide/vue';
import ConnectAgentDialog from '@/components/ConnectAgentDialog.vue';
import { Button } from '@/components/ui/button';

withDefaults(
    defineProps<{
        /**
         * Before data flows, the agent's first job is wiring Bilis in; after,
         * it is watching what arrives. Same button, different reason to press.
         */
        ready?: boolean;
        /** Overridable so the styleguide can show a stable, fake host. */
        url?: string;
    }>(),
    { ready: false, url: undefined },
);
</script>

<template>
    <!--
      One strip on the surface, not a card: the invitation to the one setup
      step that keeps paying off. The page drops it once an agent is connected.
    -->
    <section
        class="flex flex-col gap-4 rounded-lg border px-4 py-4 sm:flex-row sm:items-center sm:justify-between md:px-5"
        aria-label="Connect your coding agent"
        data-test="connect-agent-callout"
    >
        <div class="flex min-w-0 items-start gap-3">
            <span
                class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md border bg-muted/40 text-muted-foreground"
                aria-hidden="true"
            >
                <Bot class="size-4" />
            </span>
            <div class="flex min-w-0 flex-col gap-1">
                <p class="text-sm font-semibold tracking-tight">
                    Let your coding agent watch your errors
                </p>
                <p class="max-w-prose text-sm text-muted-foreground">
                    <template v-if="ready">
                        Connect Claude Code, Cursor or any MCP client and it can
                        check new issues, read the logs and traces behind them,
                        and propose a fix — without you pasting a stack trace.
                    </template>
                    <template v-else>
                        Connect Claude Code, Cursor or any MCP client and it can
                        help wire Bilis into your app, then check new issues as
                        they arrive. One command, no key to copy.
                    </template>
                </p>
            </div>
        </div>

        <ConnectAgentDialog :url="url">
            <Button class="shrink-0 self-start sm:self-auto">
                <Bot /> Connect your agent
            </Button>
        </ConnectAgentDialog>
    </section>
</template>
