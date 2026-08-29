<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CodeCanvas from '@/components/CodeCanvas.vue';
import CreateFixJobModal from '@/components/CreateFixJobModal.vue';
import DeleteLlmCredentialModal from '@/components/DeleteLlmCredentialModal.vue';
import FixJobEventRow from '@/components/FixJobEventRow.vue';
import FixJobStatusBadge from '@/components/FixJobStatusBadge.vue';
import RepositoryAutofixSettings from '@/components/RepositoryAutofixSettings.vue';
import { Button } from '@/components/ui/button';
import {
    demoFixDiff,
    demoFixExcerpt,
    demoFixJobEvents,
    demoFixJobStatuses,
    demoLlmCredentials,
    demoProjectRepositories,
} from '@/pages/styleguide/data';
import type { ProjectDetail } from '@/types';
import DemoBlock from './DemoBlock.vue';
import SectionShell from './SectionShell.vue';

const page = usePage();

/** The demo posts to the real endpoint, so it needs the real team slug. */
const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

const demoAutofixRepositories = [
    {
        id: 1,
        name: 'Checkout',
        projectName: 'Checkout',
        repoFullName: 'acme/checkout-api',
    },
    {
        id: 2,
        name: 'Checkout',
        projectName: 'Checkout',
        repoFullName: 'acme/ingest-gateway',
    },
];

const deleteCredentialOpen = ref(false);

const demoProject: ProjectDetail = {
    id: 1,
    name: 'Checkout',
    slug: 'checkout',
    allowedOrigins: ['https://checkout.example.com'],
    createdAt: '2026-06-02T11:00:00.000Z',
};

const demoObservedServices = [
    'checkout',
    'checkout-worker',
    'billing',
    'billing-worker',
    'notifications',
];
</script>

<template>
    <SectionShell
        id="autofix"
        title="Autofix"
        description="The surfaces that show what an agent did — to a production error the scan found, or to a change somebody asked for: a status ladder cut from the severity ramp, a session transcript, and the one component in the app that renders code."
    >
        <DemoBlock
            title="FixJobStatusBadge"
            description="The badge stays achromatic — the dot is the only hue, and it is borrowed from the severity ramp: teal for an outcome that landed, crimson for one that did not, nothing while the job is still deciding. Running and validating pulse."
        >
            <div class="flex flex-wrap gap-2">
                <FixJobStatusBadge
                    v-for="entry in demoFixJobStatuses"
                    :key="entry.status"
                    :status="entry.status"
                    :label="entry.label"
                />
            </div>
        </DemoBlock>

        <DemoBlock
            title="CreateFixJobModal"
            description="The one place a person, rather than the scan, starts an agent run. The picker lists repositories rather than projects — a project ships several services and may hold one repository per group of them — and lists only those that opted into autofix, the same gate the endpoint enforces. It preselects when there is exactly one. The counter turns warn-coloured below the minimum; a budget refusal comes back as a field error naming the limit that blocked it, not a toast."
        >
            <CreateFixJobModal
                :team-slug="teamSlug"
                :repositories="demoAutofixRepositories"
                :credentials="demoLlmCredentials"
            >
                <Button size="sm">New job</Button>
            </CreateFixJobModal>
        </DemoBlock>

        <DemoBlock
            title="DeleteLlmCredentialModal"
            description="Removing one of a team's model API keys. The copy is careful about what it does and does not do: no new job can run on the key, jobs that already did keep their history, and the key is NOT revoked at the provider — a customer who assumes otherwise leaves a live credential behind."
        >
            <Button
                size="sm"
                variant="secondary"
                @click="deleteCredentialOpen = true"
            >
                Remove key
            </Button>

            <DeleteLlmCredentialModal
                v-model:open="deleteCredentialOpen"
                :team-slug="teamSlug"
                :credential="demoLlmCredentials[0]"
            />
        </DemoBlock>

        <DemoBlock
            title="RepositoryAutofixSettings"
            description="One connected repository. The service claim is what makes several repositories on one project answerable: an error is fixed by the repository that claims its service, so exactly one is responsible. The first repository keeps the catch-all — every service nobody else named — which is why the ordinary one-repository project never has to think about this. The second has peeled off and names its own."
        >
            <div class="space-y-4">
                <RepositoryAutofixSettings
                    v-for="repository in demoProjectRepositories"
                    :key="repository.id"
                    :team-slug="teamSlug"
                    :project="demoProject"
                    :repository="repository"
                    :observed-services="demoObservedServices"
                />
            </div>
        </DemoBlock>

        <DemoBlock
            title="CodeCanvas — diff"
            description="Wraps @pierre/diffs (vanilla FileDiff) the way ChartCanvas wraps ECharts: loaded on demand, mounted into a ref'd element, and themed from the CSS tokens for both modes. Flip the appearance toggle — the syntax palette is the chart ramp and the add/remove tints are severity teal and crimson, so nothing here is hardcoded."
        >
            <CodeCanvas :patch="demoFixDiff" max-height="24rem" />
        </DemoBlock>

        <DemoBlock
            title="CodeCanvas — split view"
            description="The same component and the same patch, side by side. The mode is a prop, not a second component."
        >
            <CodeCanvas
                :patch="demoFixDiff"
                diff-style="split"
                max-height="24rem"
            />
        </DemoBlock>

        <DemoBlock
            title="CodeCanvas — single file"
            description="With `code` instead of `patch` the same wrapper renders one source excerpt, which is how tool-call results and stack traces are shown. The language is inferred from the filename."
        >
            <CodeCanvas
                :code="demoFixExcerpt"
                filename="ChargeOrder.php"
                max-height="16rem"
                hide-header
            />
        </DemoBlock>

        <DemoBlock
            title="FixJobEventRow"
            description="One row per session event, in the single schema Ayos uses for the live stream and the stored transcript alike. Anything that is code goes through CodeCanvas rather than a hand-rolled block."
        >
            <ol class="relative">
                <FixJobEventRow
                    v-for="(event, index) in demoFixJobEvents"
                    :key="event.seq"
                    :event="event"
                    :last="index === demoFixJobEvents.length - 1"
                />
            </ol>
        </DemoBlock>
    </SectionShell>
</template>
