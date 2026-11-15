<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ConnectAgentCard from '@/components/ConnectAgentCard.vue';
import CopyableValue from '@/components/CopyableValue.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { show as docsShow } from '@/routes/docs';

const props = withDefaults(
    defineProps<{
        /** Overridable so the styleguide can show a stable, fake host. */
        url?: string;
    }>(),
    { url: undefined },
);

const open = defineModel<boolean>('open', { default: false });

const page = usePage();

/**
 * The server's own answer first (shared as `agent.mcpUrl`); the origin is
 * only a fallback for a page rendered without it.
 */
const mcpUrl = computed(
    () =>
        props.url ??
        page.props.agent?.mcpUrl ??
        (typeof window === 'undefined' ? '' : `${window.location.origin}/mcp`),
);

const docsHref = docsShow({ section: 'reference', page: 'mcp' }).url;

/**
 * What to say once it is connected. Phrased as a standing check, not a single
 * question: the point of connecting is that the agent looks at new errors
 * without being walked there.
 */
const FIRST_ASK =
    'Check Bilis for new errors in the last 24 hours, find the cause of the worst one, and propose a fix.';
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>

        <DialogContent class="sm:max-w-xl" data-test="connect-agent-dialog">
            <DialogHeader>
                <DialogTitle>Connect your coding agent</DialogTitle>
                <DialogDescription>
                    Your agent reads this team's issues, logs, traces and
                    metrics while it works, so it can check new errors and trace
                    them to a cause without you pasting anything. It signs in as
                    you in the browser — no key to copy — and it can only read.
                </DialogDescription>
            </DialogHeader>

            <ConnectAgentCard :url="mcpUrl" :docs-href="docsHref" bare />

            <div class="flex flex-col gap-2 border-t pt-5">
                <p class="text-sm font-medium">Then ask it</p>
                <CopyableValue :value="FIRST_ASK" label="Copy the first ask" />
            </div>
        </DialogContent>
    </Dialog>
</template>
