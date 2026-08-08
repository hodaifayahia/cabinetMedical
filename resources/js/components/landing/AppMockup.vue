<script setup lang="ts">
import { Stethoscope } from '@lucide/vue';
import { computed } from 'vue';
import { type LandingLocale, translations } from './translations';

const props = defineProps<{
    locale: LandingLocale;
}>();

const copy = computed(() => translations[props.locale].mockup);

const statusTone = (index: number): string =>
    index === 1
        ? 'bg-amber-100 text-amber-700'
        : 'bg-emerald-100 text-emerald-700';
</script>

<template>
    <div
        aria-hidden="true"
        class="overflow-hidden rounded-2xl border border-border bg-card shadow-[0_20px_50px_-24px_rgba(38,70,91,0.45)]"
    >
        <!-- window chrome -->
        <div class="flex items-center gap-2 border-b border-border bg-muted/40 px-4 py-3">
            <span class="size-3 rounded-full bg-red-300"></span>
            <span class="size-3 rounded-full bg-amber-300"></span>
            <span class="size-3 rounded-full bg-emerald-300"></span>
            <span class="ms-3 text-xs font-medium text-muted-foreground">Drclick</span>
        </div>

        <div class="grid grid-cols-[130px_1fr] sm:grid-cols-[150px_1fr]">
            <!-- sidebar -->
            <div class="border-e border-border bg-sidebar p-3">
                <div class="mb-4 flex items-center gap-2">
                    <span
                        class="flex size-7 items-center justify-center rounded-lg bg-primary text-primary-foreground"
                    >
                        <Stethoscope class="size-4" />
                    </span>
                    <span class="text-xs font-bold text-sidebar-foreground">Drclick</span>
                </div>
                <ul class="space-y-1">
                    <li
                        v-for="(item, index) in copy.sidebar"
                        :key="item"
                        class="truncate rounded-lg px-2.5 py-1.5 text-xs font-medium"
                        :class="
                            index === 2
                                ? 'bg-sidebar-accent text-sidebar-accent-foreground'
                                : 'text-sidebar-foreground/70'
                        "
                    >
                        {{ item }}
                    </li>
                </ul>
            </div>

            <!-- agenda panel -->
            <div class="p-4">
                <p class="mb-3 text-sm font-semibold text-card-foreground">
                    {{ copy.agendaTitle }}
                </p>
                <div class="space-y-2">
                    <div
                        v-for="(slot, index) in copy.slots"
                        :key="slot.time"
                        class="flex items-center gap-3 rounded-xl border border-border bg-background px-3 py-2.5"
                    >
                        <span
                            class="rounded-md bg-accent px-2 py-1 font-mono text-xs font-semibold text-accent-foreground"
                        >
                            {{ slot.time }}
                        </span>
                        <span class="min-w-0 flex-1 truncate text-sm text-foreground">
                            {{ slot.name }}
                        </span>
                        <span
                            class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium"
                            :class="statusTone(index)"
                        >
                            {{ slot.status }}
                        </span>
                    </div>
                </div>
                <div class="mt-3 h-1.5 w-2/3 rounded-full bg-muted"></div>
                <div class="mt-2 h-1.5 w-1/2 rounded-full bg-muted"></div>
            </div>
        </div>
    </div>
</template>
