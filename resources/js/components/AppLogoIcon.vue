<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        :class="className"
        v-bind="$attrs"
        aria-hidden="true"
    >
        <rect
            x="3"
            y="4.8"
            width="14"
            height="3.4"
            rx="1.7"
            fill="currentColor"
        />
        <rect
            x="6"
            y="10.3"
            width="15"
            height="3.4"
            rx="1.7"
            fill="currentColor"
        />
        <rect
            x="3"
            y="15.8"
            width="11"
            height="3.4"
            rx="1.7"
            fill="currentColor"
        />
    </svg>
</template>
