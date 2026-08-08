<script setup lang="ts">
import { computed, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        src: string | null;
        alt?: string;
        imageClass?: string;
    }>(),
    {
        alt: '',
        imageClass: '',
    },
);

const loadFailed = ref(false);
const showImage = computed(() => Boolean(props.src) && !loadFailed.value);

watch(
    () => props.src,
    () => {
        loadFailed.value = false;
    },
);
</script>

<template>
    <img
        v-if="showImage"
        :src="src ?? undefined"
        :alt="alt"
        :class="imageClass"
        data-test="clinic-logo-image"
        @error="loadFailed = true"
    />
    <slot v-else />
</template>
