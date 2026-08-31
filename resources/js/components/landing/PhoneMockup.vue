<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import type { LandingLocale } from './translations';
import { translations } from './translations';

const props = defineProps<{
    locale: LandingLocale;
}>();

const copy = computed(() => translations[props.locale].mobileApp.mock);
</script>

<template>
    <div
        aria-hidden="true"
        class="w-[248px] rounded-[2.6rem] border border-border bg-card p-2.5 shadow-[0_24px_60px_-28px_rgba(0,55,66,0.5)] sm:w-[270px]"
    >
        <div
            class="overflow-hidden rounded-[2.1rem] border border-border/60 bg-background"
        >
            <!-- speaker notch -->
            <div class="flex items-center justify-center bg-card pt-2.5 pb-1.5">
                <span
                    class="h-1.5 w-16 rounded-full bg-muted-foreground/25"
                ></span>
            </div>

            <!-- app header -->
            <div
                class="flex items-center gap-2 border-b border-border bg-card px-4 pt-2 pb-3"
            >
                <AppLogoIcon class="size-6 object-contain" />
                <span class="text-xs font-bold text-foreground">Drclick</span>
            </div>

            <div class="px-4 py-4">
                <p class="text-sm font-bold text-foreground">
                    {{ copy.header }}
                </p>
                <p class="mt-0.5 text-[11px] text-muted-foreground">
                    {{ copy.chooseSlot }}
                </p>

                <div class="mt-3 space-y-2">
                    <div
                        v-for="(slot, index) in copy.slots"
                        :key="slot"
                        class="flex items-center justify-between rounded-xl border px-3 py-2.5 text-xs font-semibold"
                        :class="
                            index === 1
                                ? 'border-primary bg-brand-soft/70 text-accent-foreground'
                                : 'border-border bg-card text-foreground/80'
                        "
                    >
                        <span class="font-mono tabular-nums" dir="ltr">
                            {{ slot }}
                        </span>
                        <Check v-if="index === 1" class="size-3.5 text-primary" />
                    </div>
                </div>

                <div
                    class="mt-4 rounded-xl bg-primary px-3 py-2.5 text-center text-xs font-semibold text-primary-foreground"
                >
                    {{ copy.confirm }}
                </div>
                <div
                    class="mt-3 flex items-center justify-center gap-2 rounded-xl bg-accent/70 px-3 py-2 text-[11px] font-medium text-accent-foreground"
                >
                    <Check class="size-3.5" />
                    {{ copy.confirmed }}
                </div>
            </div>

            <!-- home indicator -->
            <div class="flex justify-center pt-1 pb-2.5">
                <span
                    class="h-1 w-20 rounded-full bg-muted-foreground/30"
                ></span>
            </div>
        </div>
    </div>
</template>
