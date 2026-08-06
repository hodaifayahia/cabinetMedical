<script setup lang="ts">
import { computed, ref } from 'vue';

type Segment = { label: string; value: number; color: string };

const props = withDefaults(
    defineProps<{
        data: Segment[];
        size?: number;
        thickness?: number;
        centerLabel?: string;
        centerValue?: string | number;
    }>(),
    { size: 200, thickness: 30 },
);

const hovered = ref<number | null>(null);

const total = computed(() =>
    props.data.reduce((sum, item) => sum + item.value, 0),
);

const radius = computed(() => (props.size - props.thickness) / 2);
const circumference = computed(() => 2 * Math.PI * radius.value);

const segments = computed(() => {
    const c = circumference.value;
    let offset = 0;

    return props.data
        .filter((item) => item.value > 0)
        .map((item) => {
            const fraction = total.value > 0 ? item.value / total.value : 0;
            const length = fraction * c;
            const segment = {
                ...item,
                dash: `${length} ${c - length}`,
                offset: -offset,
            };
            offset += length;

            return segment;
        });
});

const displayValue = computed(() => {
    if (hovered.value !== null && segments.value[hovered.value]) {
        return segments.value[hovered.value].value;
    }

    return props.centerValue ?? total.value;
});

const displayLabel = computed(() => {
    if (hovered.value !== null && segments.value[hovered.value]) {
        return segments.value[hovered.value].label;
    }

    return props.centerLabel ?? 'Total';
});
</script>

<template>
    <div
        class="relative inline-flex items-center justify-center"
        :style="{ width: `${size}px`, height: `${size}px` }"
    >
        <svg
            :viewBox="`0 0 ${size} ${size}`"
            :width="size"
            :height="size"
            class="max-w-full -rotate-90"
            role="img"
        >
            <circle
                :cx="size / 2"
                :cy="size / 2"
                :r="radius"
                fill="none"
                class="text-muted-foreground/15"
                stroke="currentColor"
                :stroke-width="thickness"
            />
            <circle
                v-for="(segment, index) in segments"
                :key="index"
                :cx="size / 2"
                :cy="size / 2"
                :r="radius"
                fill="none"
                :stroke="segment.color"
                :stroke-width="hovered === index ? thickness + 6 : thickness"
                :stroke-dasharray="segment.dash"
                :stroke-dashoffset="segment.offset"
                stroke-linecap="butt"
                class="cursor-pointer transition-all duration-200"
                :style="{
                    opacity: hovered === null || hovered === index ? 1 : 0.35,
                }"
                @mouseenter="hovered = index"
                @mouseleave="hovered = null"
            >
                <title>{{ segment.label }}: {{ segment.value }}</title>
            </circle>
        </svg>

        <div
            class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center"
        >
            <span class="text-3xl font-bold text-foreground tabular-nums">
                {{ displayValue }}
            </span>
            <span class="mt-0.5 text-xs font-medium text-muted-foreground">
                {{ displayLabel }}
            </span>
        </div>
    </div>
</template>
