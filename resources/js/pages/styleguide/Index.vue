<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    provide,
    reactive,
    ref,
} from 'vue';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import {
    demoRegistryKey,
    STYLEGUIDE_CATEGORIES,
    STYLEGUIDE_ENTRIES,
} from '@/pages/styleguide/registry';
import type { NavGroup, StyleguideDemo } from '@/pages/styleguide/registry';
import SectionShell from './partials/SectionShell.vue';
import StyleguideNav from './partials/StyleguideNav.vue';

/*
 * The styleguide is the one public Inertia surface, and it wears the shared
 * public chrome from its own root view (resources/views/styleguide.blade.php)
 * rather than the in-app sidebar — so it asks for no Inertia layout at all.
 */
defineOptions({ layout: [] });

/** Demos report themselves as they mount; see registry.ts. */
const demos = reactive(new Map<string, StyleguideDemo[]>());

provide(demoRegistryKey, {
    register(entryId: string, demo: StyleguideDemo) {
        const known = demos.get(entryId) ?? [];

        if (known.some((existing) => existing.id === demo.id)) {
            return;
        }

        demos.set(entryId, [...known, demo]);
    },
});

const query = ref('');
const activeId = ref(STYLEGUIDE_ENTRIES[0]?.id ?? '');

const needle = computed(() => query.value.trim().toLowerCase());

function demosFor(entryId: string): StyleguideDemo[] {
    return demos.get(entryId) ?? [];
}

function matches(entryId: string): boolean {
    if (!needle.value) {
        return true;
    }

    const entry = STYLEGUIDE_ENTRIES.find(
        (candidate) => candidate.id === entryId,
    );

    if (!entry) {
        return false;
    }

    return (
        entry.name.toLowerCase().includes(needle.value) ||
        entry.description.toLowerCase().includes(needle.value) ||
        demosFor(entryId).some((demo) =>
            demo.title.toLowerCase().includes(needle.value),
        )
    );
}

/**
 * The nav is derived from the registry plus whatever the demos reported, so a
 * new showcase entry turns up here without anything being wired by hand.
 */
const groups = computed<NavGroup[]>(() =>
    STYLEGUIDE_CATEGORIES.map((category) => ({
        id: category.id,
        title: category.title,
        entries: category.entries
            .filter((entry) => matches(entry.id))
            .map((entry) => {
                const nameHit =
                    !needle.value ||
                    entry.name.toLowerCase().includes(needle.value);

                const demoList = demosFor(entry.id);

                return {
                    entry,
                    demos: needle.value
                        ? demoList.filter(
                              (demo) =>
                                  nameHit ||
                                  demo.title
                                      .toLowerCase()
                                      .includes(needle.value),
                          )
                        : activeId.value === entry.id
                          ? demoList
                          : [],
                };
            }),
    })).filter((group) => group.entries.length > 0),
);

function categoryMatches(categoryId: string): boolean {
    const category = STYLEGUIDE_CATEGORIES.find(
        (candidate) => candidate.id === categoryId,
    );

    return (category?.entries ?? []).some((entry) => matches(entry.id));
}

const matchCount = computed(
    () => STYLEGUIDE_ENTRIES.filter((entry) => matches(entry.id)).length,
);

let observer: IntersectionObserver | null = null;

onMounted(() => {
    /*
     * Deep links stay honest in both directions: the hash scrolls you to a
     * section, and scrolling rewrites the hash without pushing history.
     */
    observer = new IntersectionObserver(
        (records) => {
            const visible = records
                .filter((record) => record.isIntersecting)
                .sort(
                    (a, b) =>
                        a.boundingClientRect.top - b.boundingClientRect.top,
                );

            const id = visible[0]?.target.id;

            if (!id || id === activeId.value) {
                return;
            }

            activeId.value = id;
            window.history.replaceState(null, '', `#${id}`);
        },
        { rootMargin: '-80px 0px -70% 0px', threshold: 0 },
    );

    for (const entry of STYLEGUIDE_ENTRIES) {
        const element = document.getElementById(entry.id);

        if (element) {
            observer.observe(element);
        }
    }
});

onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <Head title="Styleguide" />

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
        <header
            class="flex flex-col gap-4 border-b pb-6 sm:flex-row sm:items-end sm:justify-between"
        >
            <div class="space-y-2">
                <h1 class="text-2xl font-semibold tracking-tight">
                    Styleguide
                </h1>
                <p class="max-w-3xl text-sm text-muted-foreground">
                    The brand palette, the semantic tokens built on top of it,
                    and every component the app ships — live, not screenshotted.
                    Flip the appearance switch to check both modes: nothing on
                    this page hardcodes a colour outside the brand palette
                    table.
                </p>
            </div>

            <AppearanceTabs class="shrink-0" />
        </header>

        <div class="mt-6 grid gap-8 lg:grid-cols-[16rem_minmax(0,1fr)]">
            <StyleguideNav
                v-model:query="query"
                :groups="groups"
                :active-id="activeId"
                :match-count="matchCount"
                :total-count="STYLEGUIDE_ENTRIES.length"
            />

            <div class="min-w-0 space-y-12">
                <div
                    v-for="category in STYLEGUIDE_CATEGORIES"
                    v-show="categoryMatches(category.id)"
                    :key="category.id"
                    class="space-y-8"
                >
                    <header :id="category.id" class="scroll-mt-24 space-y-1">
                        <h2
                            class="font-mono text-xs tracking-[0.14em] text-muted-foreground uppercase"
                        >
                            {{ category.title }}
                        </h2>
                        <p class="max-w-3xl text-sm text-muted-foreground">
                            {{ category.summary }}
                        </p>
                    </header>

                    <SectionShell
                        v-for="entry in category.entries"
                        v-show="matches(entry.id)"
                        :key="entry.id"
                        :id="entry.id"
                        :title="entry.name"
                        :description="entry.description"
                        :body-class="entry.bodyClass"
                    >
                        <component :is="entry.component" />
                    </SectionShell>
                </div>

                <p
                    v-if="matchCount === 0"
                    class="rounded-lg border bg-card p-8 text-center text-sm text-muted-foreground"
                >
                    No section matches “{{ query }}”.
                </p>
            </div>
        </div>
    </div>
</template>
