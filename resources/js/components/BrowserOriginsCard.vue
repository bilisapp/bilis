<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Globe } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { update as updateBrowserOrigins } from '@/routes/projects/browser-origins';
import type { ProjectDetail } from '@/types';

type Props = {
    project: ProjectDetail;
    teamSlug: string;
};

const props = defineProps<Props>();

const form = useForm({
    origins: props.project.allowedOrigins.join('\n'),
});

const count = computed(() => props.project.allowedOrigins.length);

const submit = () => {
    form.patch(updateBrowserOrigins([props.teamSlug, props.project.slug]).url, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Card data-test="browser-origins-card">
        <CardHeader>
            <CardTitle>Browser origins</CardTitle>
            <CardDescription>
                A page can only post to Bilis from an origin listed here — the
                browser refuses the rest before the request leaves. Leave it
                empty if you only ship from servers.
            </CardDescription>
        </CardHeader>

        <CardContent class="grid gap-2">
            <Label for="browser-origins">One origin per line</Label>
            <textarea
                id="browser-origins"
                v-model="form.origins"
                rows="4"
                spellcheck="false"
                class="min-h-24 w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-input/30"
                placeholder="https://app.example.com&#10;https://*.staging.example.com"
                data-test="browser-origins-input"
            />

            <InputError :message="form.errors.origins" />

            <p class="text-xs text-muted-foreground">
                Scheme and host, with a port if it is not the default. A leading
                <code class="font-mono">*.</code> stands for one subdomain
                label; a lone <code class="font-mono">*</code> allows any
                origin, which is worth doing only while you are testing.
            </p>
        </CardContent>

        <CardFooter class="justify-between gap-3">
            <p
                class="flex items-center gap-2 text-xs text-muted-foreground"
                data-test="browser-origins-count"
            >
                <Globe class="size-3.5 shrink-0" />
                <span>
                    {{
                        count === 0
                            ? 'No browser may post to this project'
                            : `${count} allowed ${count === 1 ? 'origin' : 'origins'}`
                    }}
                </span>
            </p>

            <Button
                size="sm"
                :disabled="form.processing"
                data-test="browser-origins-save"
                @click="submit"
            >
                Save
            </Button>
        </CardFooter>
    </Card>
</template>
