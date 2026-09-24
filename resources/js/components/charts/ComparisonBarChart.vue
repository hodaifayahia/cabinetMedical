<script setup lang="ts">
import { computed, ref } from 'vue';

/**
 * Grouped bars on one shared axis: the current period next to the
 * comparison period (e.g. this year vs last year, month by month).
 * Buckets flagged `future` are drawn empty so an unfinished period never
 * shows a false drop to zero.
 */
type Point = {
    key: string;
    label: string;
    current: number;
    previous?: number | null;
    future?: boolean;
    detail?: string;
};

const props = withDefaults(
    defineProps<{
        data: Point[];
        currentLabel: string;
        previousLabel?: string | null;
        height?: number;
        selectedKey?: string | null;
        clickable?: boolean;
        formatValue?: (value: number) => string;
        formatAxis?: (value: number) => string;
    }>(),
    {
        previousLabel: null,
        height: 280,
        selectedKey: null,
        clickable: false,
        formatValue: (value: number) => String(value),
        formatAxis: (value: number) => String(value),
    },
);

const emit = defineEmits<{ select: [key: string] }>();

const vbWidth = 720;
const padLeft = 56;
const padRight = 8;
const padTop = 12;
const padBottom = 28;

const hasPrevious = computed(
    () =>
        props.previousLabel !== null &&
        props.data.some((point) => (point.previous ?? 0) !== 0),
);

const plotWidth = vbWidth - padLeft - padRight;
const plotHeight = computed(() => props.height - padTop - padBottom);

/** A "nice" rounded top of scale so gridlines land on readable values. */
const niceMax = computed(() => {
    const raw = Math.max(
        1,
        ...props.data.map((point) =>
            Math.max(
                point.current,
                hasPrevious.value ? (point.previous ?? 0) : 0,
            ),
        ),
    );
    const magnitude = 10 ** Math.floor(Math.log10(raw));
    const step = [1, 2, 2.5, 5, 10].find((s) => s * magnitude * 4 >= raw) ?? 10;

    return step * magnitude * 4;
});

const ticks = computed(() =>
    [0, 1, 2, 3, 4].map((i) => {
        const value = (niceMax.value / 4) * i;

        return {
            value,
            y:
                padTop +
                plotHeight.value -
                (value / niceMax.value) * plotHeight.value,
        };
    }),
);

const band = computed(() => plotWidth / Math.max(props.data.length, 1));

const bars = computed(() => {
    const groupWidth = Math.min(band.value * 0.72, 56);
    const barWidth = hasPrevious.value ? (groupWidth - 2) / 2 : groupWidth;
    const scale = (value: number) =>
        (Math.max(0, value) / niceMax.value) * plotHeight.value;
    const baseline = padTop + plotHeight.value;

    return props.data.map((point, index) => {
        const groupX =
            padLeft + index * band.value + (band.value - groupWidth) / 2;
        const currentHeight = point.future ? 0 : scale(point.current);
        const previousHeight = scale(point.previous ?? 0);

        return {
            ...point,
            index,
            centerX: padLeft + index * band.value + band.value / 2,
            previousBar: hasPrevious.value
                ? {
                      x: groupX,
                      y: baseline - previousHeight,
                      width: barWidth,
                      height: previousHeight,
                  }
                : null,
            currentBar: {
                x: hasPrevious.value ? groupX + barWidth + 2 : groupX,
                y: baseline - currentHeight,
                width: barWidth,
                height: currentHeight,
            },
        };
    });
});

// Only thin out labels when the axis is crowded (e.g. 31 days).
const labelEvery = computed(() => (props.data.length > 16 ? 2 : 1));

const hovered = ref<number | null>(null);
const hoveredPoint = computed(() =>
    hovered.value === null ? null : bars.value[hovered.value],
);
const tooltipLeft = computed(() =>
    hoveredPoint.value
        ? Math.min(
              Math.max((hoveredPoint.value.centerX / vbWidth) * 100, 12),
              88,
          )
        : 0,
);

const delta = (point: Point): number | null => {
    if (!point.previous || point.future) {
        return null;
    }

    return (
        Math.round(((point.current - point.previous) / point.previous) * 1000) /
        10
    );
};

/** Rounded top corners only, anchored flat on the baseline. */
const barPath = (x: number, y: number, w: number, h: number): string => {
    if (h <= 0) {
        return '';
    }

    const r = Math.min(4, w / 2, h);

    return `M${x},${y + h} V${y + r} Q${x},${y} ${x + r},${y} H${x + w - r} Q${x + w},${y} ${x + w},${y + r} V${y + h} Z`;
};
</script>

<template>
    <div class="w-full">
        <div
            v-if="hasPrevious || previousLabel"
            class="mb-3 flex flex-wrap items-center gap-4 text-xs text-muted-foreground"
        >
            <span class="inline-flex items-center gap-1.5">
                <span
                    class="size-2.5 rounded-sm"
                    style="background: var(--viz-current)"
                />
                {{ currentLabel }}
            </span>
            <span v-if="hasPrevious" class="inline-flex items-center gap-1.5">
                <span
                    class="size-2.5 rounded-sm"
                    style="background: var(--viz-previous)"
                />
                {{ previousLabel }}
            </span>
        </div>

        <div class="relative" @mouseleave="hovered = null">
            <svg
                :viewBox="`0 0 ${vbWidth} ${height}`"
                width="100%"
                :height="height"
                preserveAspectRatio="none"
                role="img"
                :aria-label="currentLabel"
            >
                <g v-for="tick in ticks" :key="`tick-${tick.value}`">
                    <line
                        :x1="padLeft"
                        :x2="vbWidth - padRight"
                        :y1="tick.y"
                        :y2="tick.y"
                        stroke="currentColor"
                        class="text-border"
                        :stroke-dasharray="tick.value === 0 ? undefined : '3 5'"
                        stroke-width="1"
                    />
                    <text
                        :x="padLeft - 8"
                        :y="tick.y + 4"
                        text-anchor="end"
                        class="fill-muted-foreground"
                        style="font-size: 11px"
                    >
                        {{ formatAxis(tick.value) }}
                    </text>
                </g>

                <g v-for="bar in bars" :key="bar.key">
                    <rect
                        v-if="bar.key === selectedKey || hovered === bar.index"
                        :x="padLeft + bar.index * band"
                        :y="padTop"
                        :width="band"
                        :height="plotHeight"
                        rx="6"
                        class="fill-muted"
                        opacity="0.7"
                    />
                    <path
                        v-if="bar.previousBar"
                        :d="
                            barPath(
                                bar.previousBar.x,
                                bar.previousBar.y,
                                bar.previousBar.width,
                                bar.previousBar.height,
                            )
                        "
                        style="fill: var(--viz-previous)"
                        :opacity="bar.future ? 0.9 : 0.85"
                    />
                    <path
                        :d="
                            barPath(
                                bar.currentBar.x,
                                bar.currentBar.y,
                                bar.currentBar.width,
                                bar.currentBar.height,
                            )
                        "
                        style="fill: var(--viz-current)"
                    />
                    <text
                        v-if="bar.index % labelEvery === 0"
                        :x="bar.centerX"
                        :y="height - 8"
                        text-anchor="middle"
                        class="fill-muted-foreground"
                        :class="bar.key === selectedKey ? 'font-semibold' : ''"
                        style="font-size: 11px"
                    >
                        {{ bar.label }}
                    </text>
                    <!-- Hit target wider and taller than the marks. -->
                    <rect
                        :x="padLeft + bar.index * band"
                        :y="0"
                        :width="band"
                        :height="height"
                        fill="transparent"
                        :class="clickable ? 'cursor-pointer' : ''"
                        @mouseenter="hovered = bar.index"
                        @click="clickable && emit('select', bar.key)"
                    />
                </g>
            </svg>

            <div
                v-if="hoveredPoint"
                class="pointer-events-none absolute top-0 z-10 min-w-44 -translate-x-1/2 rounded-xl border bg-popover px-3 py-2 text-xs shadow-lg"
                :style="{ left: `${tooltipLeft}%` }"
            >
                <p class="font-semibold text-foreground">
                    {{ hoveredPoint.detail ?? hoveredPoint.label }}
                </p>
                <p
                    v-if="hoveredPoint.future"
                    class="mt-1 text-muted-foreground"
                >
                    Période à venir
                </p>
                <p v-else class="mt-1 flex items-center justify-between gap-4">
                    <span
                        class="inline-flex items-center gap-1.5 text-muted-foreground"
                    >
                        <span
                            class="size-2 rounded-sm"
                            style="background: var(--viz-current)"
                        />
                        {{ currentLabel }}
                    </span>
                    <span class="font-semibold text-foreground tabular-nums">
                        {{ formatValue(hoveredPoint.current) }}
                    </span>
                </p>
                <p
                    v-if="hasPrevious"
                    class="mt-1 flex items-center justify-between gap-4"
                >
                    <span
                        class="inline-flex items-center gap-1.5 text-muted-foreground"
                    >
                        <span
                            class="size-2 rounded-sm"
                            style="background: var(--viz-previous)"
                        />
                        {{ previousLabel }}
                    </span>
                    <span class="font-semibold text-foreground tabular-nums">
                        {{ formatValue(hoveredPoint.previous ?? 0) }}
                    </span>
                </p>
                <p
                    v-if="delta(hoveredPoint) !== null"
                    class="mt-1 border-t pt-1 font-semibold"
                    :class="
                        (delta(hoveredPoint) ?? 0) >= 0
                            ? 'text-emerald-600 dark:text-emerald-400'
                            : 'text-rose-600 dark:text-rose-400'
                    "
                >
                    {{ (delta(hoveredPoint) ?? 0) >= 0 ? '+' : ''
                    }}{{ delta(hoveredPoint) }} %
                </p>
                <p
                    v-if="clickable && !hoveredPoint.future"
                    class="mt-1 text-[10px] text-muted-foreground"
                >
                    Cliquer pour le détail
                </p>
            </div>
        </div>
    </div>
</template>
