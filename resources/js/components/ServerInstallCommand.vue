<script setup lang="ts">
import { computed } from 'vue';
import CopyableValue from '@/components/CopyableValue.vue';
import { serverInstallCommand } from '@/lib/serverAgent';

type Props = {
    /** The plaintext key, when it is known; otherwise a placeholder is shown. */
    apiKey?: string | null;
    /** Overrides the Bilis origin the script is fetched from. */
    origin?: string;
    /** Drops the explanatory line, for tight spaces. */
    compact?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    apiKey: null,
    origin: undefined,
    compact: false,
});

const command = computed(() =>
    serverInstallCommand(
        props.origin ??
            (typeof window === 'undefined' ? '' : window.location.origin),
        props.apiKey,
    ),
);
</script>

<template>
    <div class="space-y-2" data-test="server-install-command">
        <CopyableValue :value="command" label="Copy install command" />
        <p v-if="!props.compact" class="text-xs text-muted-foreground">
            Installs a pinned, checksum-verified OpenTelemetry Collector as a
            systemd service that sends host metrics, journald logs and Docker
            container stats. Remove it with
            <code class="font-mono">bilis-agent uninstall</code>.
            <a
                href="/docs/ingestion/server-agent"
                class="underline underline-offset-4 hover:text-foreground"
                data-test="server-install-command-docs"
                >Read more</a
            >.
        </p>
        <p v-else class="text-xs text-muted-foreground">
            <a
                href="/docs/ingestion/server-agent"
                class="underline underline-offset-4 hover:text-foreground"
                data-test="server-install-command-docs"
                >What it installs</a
            >
        </p>
    </div>
</template>
