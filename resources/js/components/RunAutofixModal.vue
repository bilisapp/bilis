<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AskAiMenu from '@/components/AskAiMenu.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import {
    formatUtcTimestamp,
    SEVERITY_TEXT_CLASS,
    severityLevelFor,
} from '@/lib/logs';
import { cn } from '@/lib/utils';
import { fromLog } from '@/routes/autofix';
import type { LogEntry, TeamLlmCredential } from '@/types';

/**
 * Ask the agent to fix the error on one log line.
 *
 * A step between the click and the run, because a run costs money and opens a
 * pull request. What it shows is the two things somebody would want to check
 * first: the line itself, and which codebase is about to be worked on — the
 * repository is derived from the line's service on the server, so this is the
 * only place the reader gets to see the answer before agreeing to it.
 *
 * The scan's five-occurrence floor does not apply here. It exists to stop an
 * unattended loop spending on noise; the person in front of this dialog has
 * already made that judgement.
 */
const props = defineProps<{
    entry: LogEntry;
    teamSlug: string;
    /** The repository the endpoint will resolve for this line. */
    repository: string;
    /**
     * The team's model API keys. Offered only when there is a genuine choice —
     * with one key, or none, the server's default is the right answer and the
     * field would be noise.
     */
    credentials?: TeamLlmCredential[];
}>();

const open = ref(false);

const level = computed(() => severityLevelFor(props.entry));

const credentials = computed(() => props.credentials ?? []);
const showCredentialPicker = computed(() => credentials.value.length > 1);

/** The team's default key, which is the one the server would pick anyway. */
const defaultCredentialId = computed(
    () =>
        credentials.value.find((credential) => credential.isDefault)?.id ??
        credentials.value[0]?.id ??
        null,
);

/**
 * The row travels in the request body: the viewer already has it, and
 * ClickHouse has no key to re-read one line by. Nothing that decides
 * permissions is read from it — the project id is matched against the team's
 * own projects and the repository is derived from the service claims.
 */
const form = useForm({
    project: props.entry.projectId,
    timestamp: props.entry.timestamp,
    severityText: props.entry.severityText,
    severityNumber: props.entry.severityNumber,
    serviceName: props.entry.serviceName,
    body: props.entry.body,
    traceId: props.entry.traceId,
    spanId: props.entry.spanId,
    scopeName: props.entry.scopeName,
    scopeVersion: props.entry.scopeVersion,
    logAttributes: props.entry.logAttributes,
    resourceAttributes: props.entry.resourceAttributes,
    credential: defaultCredentialId.value,
});

/**
 * The dialog outlives nothing, but the row under it can be replaced by the
 * live tail while it is closed. Re-reading the entry on open keeps what is
 * submitted the same line the reader is looking at.
 */
watch(open, (value) => {
    if (!value) {
        return;
    }

    form.clearErrors();
    form.defaults({
        project: props.entry.projectId,
        timestamp: props.entry.timestamp,
        severityText: props.entry.severityText,
        severityNumber: props.entry.severityNumber,
        serviceName: props.entry.serviceName,
        body: props.entry.body,
        traceId: props.entry.traceId,
        spanId: props.entry.spanId,
        scopeName: props.entry.scopeName,
        scopeVersion: props.entry.scopeVersion,
        logAttributes: props.entry.logAttributes,
        resourceAttributes: props.entry.resourceAttributes,
        credential: defaultCredentialId.value,
    });
    form.reset();
});

/**
 * The endpoint can refuse for reasons that are not fields on this form — a
 * budget exhausted, or no repository responsible for the line's service — so
 * the bag is read by name rather than by key.
 */
const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

function submit() {
    form.post(fromLog(props.teamSlug).url, { preserveScroll: true });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>

        <DialogContent class="sm:max-w-xl">
            <form class="space-y-6" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>Fix this error</DialogTitle>
                    <DialogDescription>
                        The agent reads this error, works in
                        <span class="font-mono">{{ repository }}</span> and
                        comes back with a pull request. Nothing is merged for
                        you — the change goes through the same review as any
                        other.
                    </DialogDescription>
                </DialogHeader>

                <!--
                  The line as evidence, in the viewer's own monospace
                  treatment: what is about to be spent on, quoted back.
                -->
                <div
                    class="space-y-1 rounded-lg border bg-muted/40 p-3 font-mono text-xs"
                    data-test="run-autofix-preview"
                >
                    <div class="flex flex-wrap items-baseline gap-2">
                        <span class="text-muted-foreground tabular-nums">
                            {{ formatUtcTimestamp(entry.timestamp) }}
                        </span>
                        <span
                            :class="
                                cn(
                                    'font-semibold uppercase',
                                    SEVERITY_TEXT_CLASS[level],
                                )
                            "
                        >
                            {{ entry.severityText || level }}
                        </span>
                        <span class="text-muted-foreground">
                            {{ entry.serviceName || '—' }}
                        </span>
                    </div>
                    <p class="line-clamp-4 break-words">{{ entry.body }}</p>
                </div>

                <InputError :message="errors.repository" />
                <InputError :message="errors.project" />

                <div v-if="showCredentialPicker" class="grid gap-2">
                    <Label for="log-fix-credential">Model API key</Label>
                    <Select v-model="form.credential">
                        <SelectTrigger
                            id="log-fix-credential"
                            class="w-full"
                            data-test="run-autofix-credential"
                        >
                            <SelectValue placeholder="Pick a key" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="credential in credentials"
                                :key="credential.id"
                                :value="credential.id"
                            >
                                {{ credential.label }} ·
                                {{ credential.providerLabel }} ·
                                {{ credential.hint }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-muted-foreground">
                        This run is billed to the key you pick, at that
                        provider.
                    </p>
                    <InputError :message="errors.credential" />
                </div>

                <DialogFooter class="gap-2 sm:justify-between">
                    <AskAiMenu :entry="entry" align="start">
                        <Button type="button" variant="ghost" size="sm">
                            Ask an assistant instead
                        </Button>
                    </AskAiMenu>

                    <div class="flex items-center gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </DialogClose>

                        <Button
                            type="submit"
                            :disabled="form.processing"
                            data-test="run-autofix-submit"
                        >
                            <Spinner v-if="form.processing" class="size-4" />
                            {{ form.processing ? 'Queueing…' : 'Run autofix' }}
                        </Button>
                    </div>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
