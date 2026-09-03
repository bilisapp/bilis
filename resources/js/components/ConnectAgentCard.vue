<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { computed, ref } from 'vue';
import CopyableValue from '@/components/CopyableValue.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

type Props = {
    /** The MCP endpoint of this instance, resolved on the server. */
    url: string;
    /** Where the guide lives, so the card never restates it. */
    docsHref: string;
};

const props = defineProps<Props>();

/**
 * The one line that connects Claude Code.
 *
 * Everything else on the card exists to make copying this feel safe; the
 * config block below is the same URL for clients that take JSON instead.
 */
const command = computed(
    () => `claude mcp add --transport http bilis ${props.url}`,
);

const config = computed(() =>
    JSON.stringify(
        { mcpServers: { bilis: { url: props.url } } },
        null,
        4,
    ),
);

/** The JSON block is folded away: most people only need the one line. */
const showConfig = ref(false);
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>Connect your coding agent</CardTitle>
            <CardDescription>
                Let the assistant you already code with read this team's logs and traces while it
                works. It signs in as you in the browser — there is no key to paste — and it can
                only read: nothing it does can send, change or delete anything.
            </CardDescription>
        </CardHeader>

        <CardContent class="space-y-4">
            <div class="space-y-2">
                <p class="text-sm font-medium">Claude Code</p>
                <CopyableValue :value="command" label="Copy the connect command" />
            </div>

            <div class="space-y-2">
                <button
                    type="button"
                    class="flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                    :aria-expanded="showConfig"
                    @click="showConfig = !showConfig"
                >
                    <ChevronDown
                        class="size-4 transition-transform"
                        :class="{ 'rotate-180': showConfig }"
                    />
                    Claude Desktop, Cursor, or another client
                </button>

                <div v-if="showConfig" class="space-y-2">
                    <p class="text-sm text-muted-foreground">
                        Add this to the client's <code class="font-mono text-xs">mcpServers</code>
                        block and restart it.
                    </p>
                    <CopyableValue :value="config" label="Copy the client configuration" />
                </div>
            </div>

            <p class="text-sm text-muted-foreground">
                <a
                    :href="props.docsHref"
                    class="font-medium text-foreground underline underline-offset-4"
                >
                    What your agent can see
                </a>
                — the eight tools, and the line they cannot cross.
            </p>
        </CardContent>
    </Card>
</template>
