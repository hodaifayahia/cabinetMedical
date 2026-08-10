<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
    alt?: string;
};

withDefaults(defineProps<Props>(), {
    alt: '',
});
</script>

<template>
    <img
        src="/brand/drclick-mark.png"
        :alt="alt"
        :class="className"
        draggable="false"
        v-bind="$attrs"
    />
</template>
