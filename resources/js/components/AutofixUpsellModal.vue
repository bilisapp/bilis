<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { GitPullRequest, ShieldCheck, Timer } from '@lucide/vue';
import { ref } from 'vue';
import AskAiMenu from '@/components/AskAiMenu.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { show as projectShow } from '@/routes/projects';
import type { LogEntry } from '@/types';

/**
 * What the "fix this" button says when nothing can fix it yet.
 *
 * A dead button, or no button at all, teaches nobody that the feature exists.
 * This is the same affordance in the same place, answering the question the
 * click was asking: here is what a connected repository would have done with
 * this line, here is where to connect one — and here, meanwhile, is the line
 * as a question you can ask an assistant right now.
 *
 * Deliberately not a nag: it opens on a click, never on its own, and the
 * assistant route is offered as a real answer rather than as a consolation.
 */
defineProps<{
    entry: LogEntry;
    teamSlug: string;
    /** The project the line came from, when it is one of this team's. */
    projectSlug?: string | null;
    projectName?: string | null;
}>();

const open = ref(false);

const BENEFITS = [
    {
        icon: Timer,
        title: 'It starts before you do',
        body: 'The agent reads the error, its stack and the service it came from, then works in the repository that owns that service.',
    },
    {
        icon: GitPullRequest,
        title: 'It comes back as a pull request',
        body: 'Never a push to your default branch. You review the diff the way you would review anyone else’s.',
    },
    {
        icon: ShieldCheck,
        title: 'It stays inside limits you set',
        body: 'Per-repository concurrency and a daily budget, shared with the scheduled scan, so it cannot run away with your model bill.',
    },
];
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>

        <DialogContent class="sm:max-w-lg" data-test="autofix-upsell">
            <DialogHeader>
                <DialogTitle>Let an agent fix this</DialogTitle>
                <DialogDescription>
                    <template v-if="projectName">
                        No repository is connected for
                        <span class="font-medium text-foreground">{{
                            projectName
                        }}</span
                        >’s
                        <span class="font-mono">{{
                            entry.serviceName || 'services'
                        }}</span>
                        yet, so there is no codebase to work in.
                    </template>
                    <template v-else>
                        No repository is connected for this line’s service yet,
                        so there is no codebase to work in.
                    </template>
                </DialogDescription>
            </DialogHeader>

            <ul class="space-y-4">
                <li
                    v-for="benefit in BENEFITS"
                    :key="benefit.title"
                    class="flex gap-3"
                >
                    <span
                        class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground"
                    >
                        <component :is="benefit.icon" class="size-4" />
                    </span>
                    <div class="space-y-0.5">
                        <p class="text-sm font-medium">{{ benefit.title }}</p>
                        <p class="text-sm text-muted-foreground">
                            {{ benefit.body }}
                        </p>
                    </div>
                </li>
            </ul>

            <DialogFooter class="gap-2 sm:justify-between">
                <AskAiMenu :entry="entry" align="start">
                    <Button type="button" variant="outline">
                        Ask an assistant now
                    </Button>
                </AskAiMenu>

                <Button
                    v-if="projectSlug"
                    as-child
                    data-test="autofix-upsell-connect"
                >
                    <Link :href="projectShow([teamSlug, projectSlug])">
                        Connect a repository
                    </Link>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
