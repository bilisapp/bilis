<script setup lang="ts">
import {
    SEVERITY_DOT_CLASS,
    SEVERITY_LEVELS,
    SEVERITY_TEXT_CLASS,
} from '@/lib/logs';
import { cn } from '@/lib/utils';
import { SEVERITY_NOTES } from '@/pages/styleguide/data';
</script>

<template>
    <div class="overflow-x-auto rounded-lg border bg-card">
        <table class="w-full text-sm">
            <thead
                class="border-b bg-muted/40 text-left text-xs text-muted-foreground"
            >
                <tr>
                    <th class="px-4 py-2 font-medium">Dot</th>
                    <th class="px-4 py-2 font-medium">Text sample</th>
                    <th class="px-4 py-2 font-medium">Badge</th>
                    <th class="px-4 py-2 font-medium">Utility</th>
                    <th class="px-4 py-2 font-medium">Usage</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="level in SEVERITY_LEVELS"
                    :key="level"
                    class="border-b last:border-b-0"
                >
                    <td class="px-4 py-3">
                        <span
                            :class="
                                cn(
                                    'block size-3 rounded-full',
                                    SEVERITY_DOT_CLASS[level],
                                )
                            "
                        />
                    </td>
                    <td class="px-4 py-3">
                        <span
                            :class="
                                cn(
                                    'font-mono text-xs font-semibold uppercase',
                                    SEVERITY_TEXT_CLASS[level],
                                )
                            "
                        >
                            {{ level }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <span
                            :class="
                                cn(
                                    'inline-flex items-center gap-1.5 rounded-full border border-foreground/30 bg-accent px-2.5 py-1 text-xs font-medium text-accent-foreground capitalize',
                                )
                            "
                        >
                            <span
                                :class="
                                    cn(
                                        'size-2 rounded-full',
                                        SEVERITY_DOT_CLASS[level],
                                    )
                                "
                            />
                            {{ level }}
                        </span>
                    </td>
                    <td
                        class="px-4 py-3 font-mono text-xs text-muted-foreground"
                    >
                        text-severity-{{ level }}
                    </td>
                    <td class="px-4 py-3 text-xs text-muted-foreground">
                        {{ SEVERITY_NOTES[level] }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
