<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import type { MonthDay, MonthOverview } from '@/types';

const props = defineProps<{
    month: MonthOverview;
    selectedDate: string | null;
    loading?: boolean;
}>();

const emit = defineEmits<{
    'select-date': [date: string];
    'change-month': [year: number, month: number];
}>();

const weekdayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const monthLabel = computed(() =>
    new Date(props.month.year, props.month.month - 1, 1).toLocaleString(
        'en-US',
        {
            month: 'long',
            year: 'numeric',
        },
    ),
);

const leadingBlanks = computed(() =>
    props.month.days.length > 0 ? props.month.days[0].weekday - 1 : 0,
);

type DayStatus = 'past' | 'closed' | 'off' | 'free' | 'full';

const statusOf = (day: MonthDay): DayStatus => {
    if (day.is_past) {
        return 'past';
    }

    if (!day.is_open_month || !day.is_working_day) {
        return 'closed';
    }

    if (day.is_day_off) {
        return 'off';
    }

    return day.available_count > 0 ? 'free' : 'full';
};

const isClickable = (day: MonthDay): boolean => {
    const status = statusOf(day);

    return status === 'free' || status === 'full';
};

const cellClasses = (day: MonthDay): string => {
    const status = statusOf(day);
    const selected = props.selectedDate === day.date;

    const base =
        'relative flex min-h-14 flex-col rounded-md border p-1.5 text-left transition';
    const ring = selected
        ? ' ring-2 ring-primary ring-offset-1 ring-offset-background'
        : '';

    const byStatus: Record<DayStatus, string> = {
        past: ' cursor-not-allowed border-transparent bg-muted/30 text-muted-foreground/60',
        closed: ' cursor-not-allowed border-sidebar-border/50 bg-muted/20 text-muted-foreground',
        off: ' cursor-not-allowed border-red-300/60 bg-red-50 text-red-700 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300',
        free: ' cursor-pointer border-emerald-300/70 bg-emerald-50 text-emerald-900 hover:bg-emerald-100 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-200 dark:hover:bg-emerald-950/50',
        full: ' cursor-pointer border-amber-300/70 bg-amber-50 text-amber-900 hover:bg-amber-100 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200',
    };

    return base + byStatus[status] + ring;
};

const goPrev = () => {
    let year = props.month.year;
    let month = props.month.month - 1;

    if (month < 1) {
        month = 12;
        year -= 1;
    }

    emit('change-month', year, month);
};

const goNext = () => {
    let year = props.month.year;
    let month = props.month.month + 1;

    if (month > 12) {
        month = 1;
        year += 1;
    }

    emit('change-month', year, month);
};

const onDayClick = (day: MonthDay) => {
    if (isClickable(day)) {
        emit('select-date', day.date);
    }
};
</script>

<template>
    <div
        class="rounded-xl border border-sidebar-border/70 bg-background p-3 dark:border-sidebar-border"
    >
        <div class="flex items-center justify-between gap-2">
            <h3 class="text-sm font-semibold text-foreground">
                {{ monthLabel }}
            </h3>
            <div class="flex items-center gap-1">
                <Button
                    variant="outline"
                    size="icon"
                    :disabled="loading"
                    aria-label="Previous month"
                    @click="goPrev"
                >
                    <ChevronLeft class="size-4" />
                </Button>
                <Button
                    variant="outline"
                    size="icon"
                    :disabled="loading"
                    aria-label="Next month"
                    @click="goNext"
                >
                    <ChevronRight class="size-4" />
                </Button>
            </div>
        </div>

        <p
            v-if="!month.is_open_month"
            class="mt-3 rounded-md bg-muted/40 px-3 py-2 text-sm text-muted-foreground"
        >
            This month is not open for booking yet.
        </p>

        <div
            class="mt-4 grid grid-cols-7 gap-1 text-center text-xs font-medium text-muted-foreground uppercase"
        >
            <span v-for="label in weekdayLabels" :key="label" class="py-1">{{
                label
            }}</span>
        </div>

        <div
            class="mt-1 grid grid-cols-7 gap-1"
            :class="{ 'pointer-events-none opacity-60': loading }"
        >
            <span
                v-for="blank in leadingBlanks"
                :key="`blank-${blank}`"
                aria-hidden="true"
            />

            <button
                v-for="day in month.days"
                :key="day.date"
                type="button"
                :class="cellClasses(day)"
                :disabled="!isClickable(day)"
                @click="onDayClick(day)"
            >
                <span class="text-sm leading-none font-semibold">{{
                    day.day
                }}</span>
                <span
                    v-if="statusOf(day) === 'free'"
                    class="mt-auto text-[11px] leading-none font-medium"
                >
                    {{ day.available_count }} free
                </span>
                <span
                    v-else-if="statusOf(day) === 'full'"
                    class="mt-auto text-[11px] leading-none font-medium"
                    >Full</span
                >
                <span
                    v-else-if="statusOf(day) === 'off'"
                    class="mt-auto text-[11px] leading-none font-medium"
                    >Off</span
                >
            </button>
        </div>

        <div
            class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground"
        >
            <span class="flex items-center gap-1.5">
                <span
                    class="size-3 rounded-sm border border-emerald-300/70 bg-emerald-100 dark:bg-emerald-900/40"
                />
                Available
            </span>
            <span class="flex items-center gap-1.5">
                <span
                    class="size-3 rounded-sm border border-amber-300/70 bg-amber-100 dark:bg-amber-900/40"
                />
                Fully booked
            </span>
            <span class="flex items-center gap-1.5">
                <span
                    class="size-3 rounded-sm border border-red-300/60 bg-red-100 dark:bg-red-900/40"
                />
                Day off
            </span>
            <span class="flex items-center gap-1.5">
                <span
                    class="size-3 rounded-sm border border-sidebar-border/70 bg-muted/40"
                />
                Closed
            </span>
        </div>
    </div>
</template>
