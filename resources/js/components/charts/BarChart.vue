<script setup lang="ts">
import { computed, ref } from 'vue';

type Bar = { label: string; value: number };

const props = withDefaults(
    defineProps<{
        data: Bar[];
        color?: string;
        height?: number;
        formatValue?: (value: number) => string;
    }>(),
    {
        color: '#00666f',
        height: 240,
        formatValue: (value: number) => String(value),
    },
);

const vbWidth = 640;
const padX = 12;
const padTop = 14;
const padBottom = 28;

const plotWidth = vbWidth - padX * 2;
const plotHeight = computed(() => props.height - padTop - padBottom);
const baseline = computed(() => padTop + plotHeight.value);

const maxValue = computed(() =>
    Math.max(...props.data.map((item) => item.value), 1),
);

const hovered = ref<number | null>(null);

const bars = computed(() => {
    const band = plotWidth / Math.max(props.data.length, 1);
    const barWidth = Math.min(band * 0.6, 42);

    return props.data.map((item, index) => {
        const barHeight = (item.value / maxValue.value) * plotHeight.value;
        const x = padX + index * band + (band - barWidth) / 2;
        const y = baseline.value - barHeight;

        return {
            ...item,
            x,
            y,
            width: barWidth,
            height: Math.max(barHeight, item.value > 0 ? 2 : 0),
            showLabel: props.data.length <= 8 || index % 2 === 0,
            center: x + barWidth / 2,
        };
    });
});
</script>

<template>
    <div class="w-full">
        <svg
            v-if="data.length"
            :viewBox="`0 0 ${vbWidth} ${height}`"
            width="100%"
            :height="height"
            preserveAspectRatio="none"
        >
            <line
                :x1="padX"
                :y1="baseline"
                :x2="vbWidth - padX"
                :y2="baseline"
                class="text-border"
                stroke="currentColor"
                stroke-width="1"
            />

            <g v-for="(bar, index) in bars" :key="index">
                <rect
                    :x="bar.x"
                    :y="bar.y"
                    :width="bar.width"
                    :height="bar.height"
                    rx="5"
                    :fill="color"
                    class="cursor-pointer transition-opacity duration-150"
                    :style="{
                        opacity:
                            hovered === null || hovered === index ? 1 : 0.4,
                    }"
                    @mouseenter="hovered = index"
                    @mouseleave="hovered = null"
                >
                    <title>{{ bar.label }}: {{ formatValue(bar.value) }}</title>
                </rect>
                <text
                    v-if="bar.showLabel"
                    :x="bar.center"
                    :y="height - 9"
                    text-anchor="middle"
                    class="fill-current text-muted-foreground"
                    style="font-size: 11px"
                >
                    {{ bar.label }}
                </text>
            </g>
        </svg>

        <p
            v-else
            class="flex items-center justify-center text-sm text-muted-foreground"
            :style="{ height: `${height}px` }"
        >
            No data to display yet.
        </p>
    </div>
</template>
