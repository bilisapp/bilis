<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy, update } from '@/routes/projects/repository';
import type { ProjectDetail, ProjectRepository } from '@/types';

/**
 * One connected repository: which services it fixes, and the budgets that stop
 * it spending a day's attempts in an hour.
 *
 * The service claim is the part that makes several repositories on one project
 * answerable. A project ships several services and they need not share a
 * codebase, so an error has to resolve to exactly one repository — otherwise
 * every repository raises a job for every error, and one of them is always the
 * wrong codebase.
 *
 * The catch-all ("everything nobody else claimed") is the default a single
 * repository keeps, so the ordinary project never has to think about this.
 */
const CATCH_ALL = '*';

const props = defineProps<{
    teamSlug: string;
    project: ProjectDetail;
    repository: ProjectRepository;
    /** Service names actually seen in this project's logs, for autocomplete. */
    observedServices: string[];
}>();

const settings = useForm({
    autofix_enabled: props.repository.autofixEnabled,
    test_cmd: props.repository.testCmd ?? '',
    max_concurrent: props.repository.maxConcurrent,
    daily_budget: props.repository.dailyBudget,
    services: [...props.repository.services],
});

const catchAll = computed({
    get: () => settings.services.includes(CATCH_ALL),
    set: (value: boolean) => {
        settings.services = value
            ? [...settings.services.filter((s) => s !== CATCH_ALL), CATCH_ALL]
            : settings.services.filter((s) => s !== CATCH_ALL);
    },
});

const claimed = computed(() =>
    settings.services.filter((service) => service !== CATCH_ALL),
);

const draft = ref('');

/** Services this project has logged that nothing has claimed here yet. */
const suggestions = computed(() =>
    props.observedServices.filter(
        (service) => !settings.services.includes(service),
    ),
);

function addService(name?: string) {
    const value = (name ?? draft.value).trim();

    if (value === '' || settings.services.includes(value)) {
        draft.value = '';

        return;
    }

    settings.services = [...settings.services, value];
    draft.value = '';
}

function removeService(name: string) {
    settings.services = settings.services.filter((service) => service !== name);
}

watch(
    () => props.repository,
    (repository) => {
        settings.defaults({
            autofix_enabled: repository.autofixEnabled,
            test_cmd: repository.testCmd ?? '',
            max_concurrent: repository.maxConcurrent,
            daily_budget: repository.dailyBudget,
            services: [...repository.services],
        });
        settings.reset();
    },
);

const disconnectForm = useForm({});

function save() {
    settings
        .transform((data) => ({ ...data, test_cmd: data.test_cmd || null }))
        .patch(
            update([props.teamSlug, props.project.slug, props.repository.id])
                .url,
            { preserveScroll: true },
        );
}

function disconnect() {
    disconnectForm.delete(
        destroy([props.teamSlug, props.project.slug, props.repository.id]).url,
        { preserveScroll: true },
    );
}
</script>

<template>
    <form
        class="space-y-5 rounded-lg border border-border p-4"
        data-test="repository-autofix-settings"
        @submit.prevent="save"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <Badge variant="secondary" class="font-mono">
                {{ repository.repoFullName }}
            </Badge>

            <span class="font-mono text-xs text-muted-foreground">
                {{ repository.defaultBranch }}
            </span>
        </div>

        <div class="flex items-start gap-3">
            <Checkbox
                :id="`autofix-enabled-${repository.id}`"
                :model-value="settings.autofix_enabled"
                data-test="project-repository-enabled"
                @update:model-value="settings.autofix_enabled = $event === true"
            />
            <div class="space-y-1">
                <Label
                    :for="`autofix-enabled-${repository.id}`"
                    class="font-medium"
                >
                    Let autofix open pull requests from this repository
                </Label>
                <p class="text-sm text-muted-foreground">
                    Off by default. With it off, the repository stays connected
                    but nothing is ever dispatched.
                </p>
            </div>
        </div>

        <!-- Which services this codebase answers for. -->
        <div class="grid gap-2">
            <Label>Services</Label>

            <div class="flex items-start gap-3">
                <Checkbox
                    :id="`autofix-catch-all-${repository.id}`"
                    :model-value="catchAll"
                    data-test="project-repository-catch-all"
                    @update:model-value="catchAll = $event === true"
                />
                <Label
                    :for="`autofix-catch-all-${repository.id}`"
                    class="text-sm font-normal text-muted-foreground"
                >
                    Fix every service no other repository claims
                </Label>
            </div>

            <div v-if="claimed.length" class="flex flex-wrap gap-2">
                <Badge
                    v-for="service in claimed"
                    :key="service"
                    variant="outline"
                    class="gap-1 font-mono"
                >
                    {{ service }}
                    <button
                        type="button"
                        class="text-muted-foreground hover:text-foreground"
                        :aria-label="`Remove ${service}`"
                        @click="removeService(service)"
                    >
                        <X class="size-3" />
                    </button>
                </Badge>
            </div>

            <div class="flex gap-2">
                <Input
                    v-model="draft"
                    :list="`services-${repository.id}`"
                    class="font-mono"
                    placeholder="checkout-api"
                    data-test="project-repository-service-input"
                    @keydown.enter.prevent="addService()"
                />
                <datalist :id="`services-${repository.id}`">
                    <option
                        v-for="service in suggestions"
                        :key="service"
                        :value="service"
                    />
                </datalist>
                <Button type="button" variant="secondary" @click="addService()">
                    Add
                </Button>
            </div>

            <p class="text-xs text-muted-foreground">
                The
                <code class="font-mono">service.name</code> your telemetry ships
                under. An error is fixed by the one repository that claims its
                service, so a repository claiming nothing is never scanned.
            </p>

            <InputError :message="settings.errors.services" />
        </div>

        <div class="grid gap-2">
            <Label :for="`autofix-test-cmd-${repository.id}`">
                Test command
            </Label>
            <Input
                :id="`autofix-test-cmd-${repository.id}`"
                v-model="settings.test_cmd"
                class="font-mono"
                placeholder="php artisan test --compact"
                data-test="project-repository-test-cmd"
            />
            <p class="text-xs text-muted-foreground">
                Run inside the agent's sandbox before a diff is accepted. Leave
                empty when the suite needs services the sandbox has no access to
                — CI on the pull request then does the proving.
            </p>
            <InputError :message="settings.errors.test_cmd" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label :for="`autofix-max-concurrent-${repository.id}`">
                    Concurrent jobs
                </Label>
                <Input
                    :id="`autofix-max-concurrent-${repository.id}`"
                    v-model.number="settings.max_concurrent"
                    type="number"
                    min="1"
                    max="10"
                    data-test="project-repository-max-concurrent"
                />
                <InputError :message="settings.errors.max_concurrent" />
            </div>

            <div class="grid gap-2">
                <Label :for="`autofix-daily-budget-${repository.id}`">
                    Jobs per day
                </Label>
                <Input
                    :id="`autofix-daily-budget-${repository.id}`"
                    v-model.number="settings.daily_budget"
                    type="number"
                    min="1"
                    max="100"
                    data-test="project-repository-daily-budget"
                />
                <InputError :message="settings.errors.daily_budget" />
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <Button
                type="submit"
                :disabled="settings.processing"
                data-test="project-repository-save"
            >
                Save settings
            </Button>

            <Button
                type="button"
                variant="ghost"
                class="text-destructive hover:text-destructive"
                :disabled="disconnectForm.processing"
                data-test="project-repository-disconnect"
                @click="disconnect"
            >
                Disconnect
            </Button>
        </div>
    </form>
</template>
