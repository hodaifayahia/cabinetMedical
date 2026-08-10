<script setup lang="ts">
import { computed } from 'vue';

type Item = { label: string; value: number; color?: string };

const props = withDefaults(
    defineProps<{
        data: Item[];
        formatValue?: (value: number) => string;
    }>(),
    { formatValue: (value: number) => String(value) },
);

const palette = [
    '#00666f',
    '#59f59b',
    '#00424a',
    '#279f79',
    '#53c9b8',
    '#8af8b9',
];

const maxValue = computed(() =>
    Math.max(...props.data.map((item) => item.value), 1),
);

const rows = computed(() =>
    props.data.map((item, index) => ({
        ...item,
        pct: (item.value / maxValue.value) * 100,
        color: item.color ?? palette[index % palette.length],
    })),
);
</script>

<template>
    <div class="space-y-4">
        <div v-for="(row, index) in rows" :key="index" class="space-y-1.5">
            <div class="flex items-center justify-between gap-3 text-sm">
                <span class="truncate text-foreground">{{ row.label }}</span>
                <span class="font-semibold text-muted-foreground tabular-nums">
                    {{ formatValue(row.value) }}
                </span>
            </div>
            <div class="h-2.5 w-full overflow-hidden rounded-full bg-muted">
                <div
                    class="h-full rounded-full transition-all duration-700"
                    :style="{
                        width: `${row.pct}%`,
                        backgroundColor: row.color,
                    }"
                />
            </div>
        </div>

        <p
            v-if="!data.length"
            class="py-8 text-center text-sm text-muted-foreground"
        >
            No data to display yet.
        </p>
    </div>
</template>
