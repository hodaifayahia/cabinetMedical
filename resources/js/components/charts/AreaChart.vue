<script setup lang="ts">
import { computed, ref } from 'vue';

type Point = { label: string; value: number };

const props = withDefaults(
    defineProps<{
        data: Point[];
        color?: string;
        height?: number;
        formatValue?: (value: number) => string;
    }>(),
    {
        color: '#00666f',
        height: 260,
        formatValue: (value: number) => String(value),
    },
);

const vbWidth = 640;
const padX = 12;
const padTop = 18;
const padBottom = 30;

const plotWidth = vbWidth - padX * 2;
const plotHeight = computed(() => props.height - padTop - padBottom);
const baseline = computed(() => padTop + plotHeight.value);

const maxValue = computed(() =>
    Math.max(...props.data.map((item) => item.value), 1),
);

const points = computed(() =>
    props.data.map((item, index) => {
        const x =
            props.data.length > 1
                ? padX + (index * plotWidth) / (props.data.length - 1)
                : padX + plotWidth / 2;
        const y = padTop + (1 - item.value / maxValue.value) * plotHeight.value;

        return { x, y, ...item };
    }),
);

const hovered = ref<number | null>(null);

const gridLines = computed(() =>
    [0, 0.25, 0.5, 0.75, 1].map((fraction) => ({
        y: padTop + fraction * plotHeight.value,
        value: Math.round(maxValue.value * (1 - fraction)),
    })),
);

const linePath = computed(() => {
    const pts = points.value;

    if (pts.length === 0) {
        return '';
    }

    if (pts.length === 1) {
        return `M ${pts[0].x} ${pts[0].y}`;
    }

    let d = `M ${pts[0].x} ${pts[0].y}`;

    for (let i = 0; i < pts.length - 1; i++) {
        const p0 = pts[i - 1] ?? pts[i];
        const p1 = pts[i];
        const p2 = pts[i + 1];
        const p3 = pts[i + 2] ?? p2;
        const cp1x = p1.x + (p2.x - p0.x) / 6;
        const cp1y = p1.y + (p2.y - p0.y) / 6;
        const cp2x = p2.x - (p3.x - p1.x) / 6;
        const cp2y = p2.y - (p3.y - p1.y) / 6;
        d += ` C ${cp1x} ${cp1y}, ${cp2x} ${cp2y}, ${p2.x} ${p2.y}`;
    }

    return d;
});

const areaPath = computed(() => {
    const pts = points.value;

    if (pts.length === 0) {
        return '';
    }

    return `${linePath.value} L ${pts[pts.length - 1].x} ${baseline.value} L ${pts[0].x} ${baseline.value} Z`;
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
            class="overflow-visible"
        >
            <g
                v-for="(line, index) in gridLines"
                :key="`grid-${index}`"
                class="text-border"
            >
                <line
                    :x1="padX"
                    :y1="line.y"
                    :x2="vbWidth - padX"
                    :y2="line.y"
                    stroke="currentColor"
                    stroke-width="1"
                    stroke-dasharray="4 6"
                    opacity="0.6"
                />
            </g>

            <path :d="areaPath" :fill="color" fill-opacity="0.12" />
            <path
                :d="linePath"
                fill="none"
                :stroke="color"
                stroke-width="2.5"
                stroke-linecap="round"
                stroke-linejoin="round"
            />

            <g v-for="(point, index) in points" :key="`pt-${index}`">
                <circle
                    :cx="point.x"
                    :cy="point.y"
                    :r="hovered === index ? 6 : 4"
                    :fill="color"
                    class="cursor-pointer transition-all duration-150"
                    stroke="var(--color-background, #fff)"
                    stroke-width="2"
                    @mouseenter="hovered = index"
                    @mouseleave="hovered = null"
                >
                    <title>
                        {{ point.label }}: {{ formatValue(point.value) }}
                    </title>
                </circle>
                <text
                    :x="point.x"
                    :y="height - 10"
                    text-anchor="middle"
                    class="fill-current text-muted-foreground"
                    style="font-size: 11px"
                >
                    {{ point.label }}
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
