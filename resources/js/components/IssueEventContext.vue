<script setup lang="ts">
import { computed } from 'vue';
import KeyValueList from '@/components/KeyValueList.vue';
import PageSection from '@/components/PageSection.vue';
import { contextSections, eventTags } from '@/lib/issues';
import type { LogEntry } from '@/types';

/**
 * What one event knew about its surroundings: the request, the user, the
 * runtime, the release and the application's own contexts, each as a panel
 * of its own, plus the tags the SDK sent. Renders nothing when the event
 * carried none of them.
 */
const props = defineProps<{
    row: LogEntry;
}>();

const sections = computed(() => contextSections(props.row));

const tags = computed(() => eventTags(props.row));
</script>

<template>
    <PageSection
        v-if="sections.length > 0 || tags.length > 0"
        title="Context"
        note="this event"
        data-test="issue-context"
    >
        <div class="grid grid-cols-1 gap-x-10 gap-y-6 pt-1 md:grid-cols-2">
            <div
                v-for="section in sections"
                :key="section.title"
                class="min-w-0"
            >
                <h3 class="pb-1 eyebrow">{{ section.title }}</h3>
                <KeyValueList :entries="section.values" />
            </div>
            <div v-if="tags.length > 0" class="min-w-0">
                <h3 class="pb-2 eyebrow">Tags</h3>
                <div class="flex flex-wrap gap-1.5">
                    <span
                        v-for="[key, value] in tags"
                        :key="key"
                        class="inline-flex h-6 max-w-full items-center overflow-hidden rounded-md text-xs ring-1 ring-border ring-inset"
                    >
                        <span
                            class="flex h-full items-center bg-muted px-2 text-muted-foreground"
                            >{{ key }}</span
                        >
                        <span
                            class="truncate px-2 font-mono text-foreground/80"
                            >{{ value }}</span
                        >
                    </span>
                </div>
            </div>
        </div>
    </PageSection>
</template>
