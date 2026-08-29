<script setup lang="ts">
import { Search } from '@lucide/vue';
import { Input } from '@/components/ui/input';
import type { NavGroup } from '@/pages/styleguide/registry';

defineProps<{
    groups: NavGroup[];
    activeId: string;
    matchCount: number;
    totalCount: number;
}>();

const query = defineModel<string>('query', { required: true });
</script>

<template>
    <nav
        aria-label="Styleguide sections"
        class="max-h-[22rem] space-y-3 overflow-y-auto rounded-lg border bg-card p-3 lg:sticky lg:top-20 lg:max-h-[calc(100dvh-6rem)] lg:rounded-none lg:border-0 lg:bg-transparent lg:p-0 lg:pr-2"
    >
        <div class="relative">
            <Search
                class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                v-model="query"
                type="search"
                class="h-9 bg-card pl-8"
                placeholder="Filter components…"
                aria-label="Filter the styleguide"
            />
        </div>

        <p class="px-1 text-xs text-muted-foreground">
            <template v-if="query">
                {{ matchCount }} of {{ totalCount }} sections match
            </template>
            <template v-else> {{ totalCount }} sections </template>
        </p>

        <ul class="space-y-4">
            <li v-for="group in groups" :key="group.id" class="space-y-1">
                <p
                    class="px-2 font-mono text-[11px] tracking-[0.14em] text-muted-foreground uppercase"
                >
                    {{ group.title }}
                </p>

                <ul class="space-y-0.5">
                    <li v-for="item in group.entries" :key="item.entry.id">
                        <a
                            :href="`#${item.entry.id}`"
                            :aria-current="
                                activeId === item.entry.id ? 'true' : undefined
                            "
                            :class="[
                                'block rounded-md px-2 py-1.5 text-sm transition-colors',
                                activeId === item.entry.id
                                    ? 'bg-accent font-medium text-accent-foreground'
                                    : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                            ]"
                        >
                            {{ item.entry.name }}
                        </a>

                        <ul
                            v-if="item.demos.length"
                            class="mt-0.5 space-y-px border-l pl-2"
                        >
                            <li v-for="demo in item.demos" :key="demo.id">
                                <a
                                    :href="`#${demo.id}`"
                                    class="block truncate rounded-md px-2 py-1 font-mono text-xs text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
                                >
                                    {{ demo.title }}
                                </a>
                            </li>
                        </ul>
                    </li>
                </ul>
            </li>
        </ul>

        <p
            v-if="!groups.length"
            class="px-2 py-4 text-sm text-muted-foreground"
        >
            Nothing matches “{{ query }}”.
        </p>
    </nav>
</template>
