<script setup lang="ts">
import { computed } from 'vue';

/**
 * 24-hour time picker (« 14:30 »). The native <input type="time"> follows
 * the browser language and shows AM/PM on English systems, which led to
 * « 11:30 PM » being saved instead of 11:30 in the morning.
 */
const props = withDefaults(
    defineProps<{
        id?: string;
        disabled?: boolean;
        step?: number;
    }>(),
    { id: undefined, disabled: false, step: 5 },
);

const model = defineModel<string>({ required: true });

const pad = (value: number): string => String(value).padStart(2, '0');

const parts = computed(() => {
    const [hour = '09', minute = '00'] = (model.value || '09:00').split(':');

    return { hour: pad(Number(hour) || 0), minute: pad(Number(minute) || 0) };
});

const hours = Array.from({ length: 24 }, (_, index) => pad(index));

// Keep an existing off-step minute (e.g. :03) selectable instead of
// silently rewriting it.
const minutes = computed(() => {
    const list = Array.from(
        { length: Math.ceil(60 / props.step) },
        (_, index) => pad(index * props.step),
    );

    return list.includes(parts.value.minute)
        ? list
        : [...list, parts.value.minute].sort();
});

const setHour = (hour: string) => {
    model.value = `${hour}:${parts.value.minute}`;
};

const setMinute = (minute: string) => {
    model.value = `${parts.value.hour}:${minute}`;
};

const selectClass =
    'h-10 flex-1 rounded-xl border border-input bg-background px-3 text-sm tabular-nums shadow-sm disabled:cursor-not-allowed disabled:opacity-50';
</script>

<template>
    <div class="flex items-center gap-1.5">
        <select
            :id="id"
            :value="parts.hour"
            :disabled="disabled"
            :class="selectClass"
            aria-label="Heure"
            @change="setHour(($event.target as HTMLSelectElement).value)"
        >
            <option v-for="hour in hours" :key="hour" :value="hour">
                {{ hour }} h
            </option>
        </select>
        <span class="font-semibold text-muted-foreground">:</span>
        <select
            :value="parts.minute"
            :disabled="disabled"
            :class="selectClass"
            aria-label="Minutes"
            @change="setMinute(($event.target as HTMLSelectElement).value)"
        >
            <option v-for="minute in minutes" :key="minute" :value="minute">
                {{ minute }}
            </option>
        </select>
    </div>
</template>
