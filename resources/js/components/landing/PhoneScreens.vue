<script setup lang="ts">
import type { LandingCopy, MobileScreen } from './translations';

/**
 * Three real captures of the patient app (search, booking, home) in phone
 * frames. The middle one leads; the side ones only appear from `sm` up.
 */
defineProps<{
    screens: LandingCopy['mobileApp']['screens'];
}>();

const src = (screen: MobileScreen): string =>
    `/images/landing/mobile/${screen}.webp`;

// Side phones tuck 40px under the middle one, so their captions lean away
// from it; only the frames tilt, never the text.
const placement = (index: number): string =>
    [
        'hidden sm:block -me-10 mt-14 w-[175px]',
        'relative z-10 w-[230px]',
        'hidden sm:block -ms-10 mt-14 w-[175px]',
    ][index] ?? '';

const tilt = (index: number): string =>
    ['-rotate-6', '', 'rotate-6'][index] ?? '';

const captionAlign = (index: number): string =>
    ['text-left pe-12', 'text-center', 'text-right ps-12'][index] ?? '';
</script>

<template>
    <!-- The captures are French-UI phones; the row keeps its visual order in
         both directions. -->
    <div class="flex items-start justify-center" dir="ltr">
        <figure
            v-for="(item, index) in screens"
            :key="item.screen"
            class="shrink-0"
            :class="placement(index)"
        >
            <div
                class="rounded-[2.4rem] border border-border bg-card p-2 shadow-[0_28px_60px_-30px_rgba(0,55,66,0.55)]"
                :class="tilt(index)"
            >
                <img
                    :src="src(item.screen)"
                    :alt="item.alt"
                    width="585"
                    height="1266"
                    loading="lazy"
                    decoding="async"
                    class="block w-full rounded-[1.95rem] border border-border/60"
                />
            </div>
            <figcaption
                class="mt-4 text-xs font-semibold text-muted-foreground"
                :class="captionAlign(index)"
            >
                {{ item.label }}
            </figcaption>
        </figure>
    </div>
</template>
