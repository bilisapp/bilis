<script setup lang="ts">
import { computed } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

/**
 * A filter as one chip: "Service  All ⌄".
 *
 * The label lives inside the trigger, muted, with the chosen value beside it
 * in ink — so a row of filters reads as a sentence and costs one control's
 * width each, instead of a label column and a box. `null` is "all" and is
 * offered as the first option under `allLabel`.
 */
const props = withDefaults(
    defineProps<{
        label: string;
        modelValue: string | null;
        options: { value: string; label: string }[];
        /** Offer "all" (null) first; omit for a filter that always has a value. */
        allLabel?: string;
        /** What the chip says when "all" is chosen; defaults to "All". */
        allShort?: string;
        id?: string;
        dataTest?: string;
    }>(),
    {
        allLabel: undefined,
        allShort: 'All',
        id: undefined,
        dataTest: undefined,
    },
);

const emit = defineEmits<{
    (event: 'update:modelValue', value: string | null): void;
}>();

const ALL = '__all__';

const model = computed({
    get: () => props.modelValue ?? ALL,
    set: (value: string) =>
        emit('update:modelValue', value === ALL ? null : value),
});

const shown = computed(() => {
    if (props.modelValue === null) {
        return props.allShort;
    }

    return (
        props.options.find((option) => option.value === props.modelValue)
            ?.label ?? props.modelValue
    );
});
</script>

<template>
    <Select v-model="model">
        <SelectTrigger
            :id="id"
            class="h-9 gap-1.5 rounded-lg border-border pr-2.5 pl-3 text-sm shadow-none dark:bg-transparent"
            :data-test="dataTest"
            :aria-label="label"
        >
            <span class="text-muted-foreground">{{ label }}</span>
            <!--
              SelectValue keeps reka's own bookkeeping; the visible text is
              ours, so "all" can read as a short "All" in the chip while the
              menu spells out "All services".
            -->
            <SelectValue class="sr-only" />
            <span class="max-w-40 truncate font-medium text-foreground">{{
                shown
            }}</span>
        </SelectTrigger>
        <SelectContent>
            <SelectItem v-if="allLabel" :value="ALL">{{ allLabel }}</SelectItem>
            <SelectItem
                v-for="option in options"
                :key="option.value"
                :value="option.value"
            >
                {{ option.label }}
            </SelectItem>
        </SelectContent>
    </Select>
</template>
