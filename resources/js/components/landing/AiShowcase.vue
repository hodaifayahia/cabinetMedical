<script setup lang="ts">
import {
    Activity,
    BookOpenCheck,
    ClipboardList,
    Coins,
    FlaskConical,
    Info,
    MessagesSquare,
    Mic,
    NotebookPen,
    Pill,
    ScanText,
    ShieldAlert,
    Sparkles,
    UserCheck,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import type { AiShot, LandingCopy } from './translations';

/**
 * Clinical AI section of the landing page: real captures of the AI features
 * (taken on invented demo patients), every AI action the app offers, and the
 * rules that keep the doctor in charge.
 */
const props = defineProps<{
    copy: LandingCopy['ai'];
    dir: 'rtl' | 'ltr';
    eyebrowTracking: string;
}>();

const SHOT_DIR = '/images/landing/ai';

const shotIcons: Record<AiShot, Component> = {
    copilot: Sparkles,
    prescription: Pill,
    visit: NotebookPen,
    ecg: Activity,
    analysis: ClipboardList,
};

// Same order as copy.capabilities.
const capabilityIcons: Component[] = [
    MessagesSquare,
    Mic,
    NotebookPen,
    Pill,
    FlaskConical,
    ScanText,
    Activity,
    ClipboardList,
];

// Same order as copy.principles.
const principleIcons: Component[] = [
    UserCheck,
    BookOpenCheck,
    ShieldAlert,
    Coins,
];

const active = ref(0);
const activeItem = computed(
    () => props.copy.items[active.value] ?? props.copy.items[0],
);

const shotSrc = (shot: AiShot): string => `${SHOT_DIR}/${shot}.webp`;
const shotSrcset = (shot: AiShot): string =>
    `${SHOT_DIR}/${shot}-800.webp 800w, ${SHOT_DIR}/${shot}.webp 1600w`;

// Roving arrow keys, mirrored in RTL so "next" follows the screen order.
function moveFocus(event: KeyboardEvent, index: number): void {
    if (!['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(event.key)) {
        return;
    }

    event.preventDefault();

    const count = props.copy.items.length;
    const forward = props.dir === 'rtl' ? 'ArrowLeft' : 'ArrowRight';
    let next = index;

    if (event.key === 'Home') {
        next = 0;
    } else if (event.key === 'End') {
        next = count - 1;
    } else {
        next = (index + (event.key === forward ? 1 : -1) + count) % count;
    }

    active.value = next;
    document.getElementById(`ai-tab-${next}`)?.focus();
}
</script>

<template>
    <section
        id="ia"
        class="relative isolate scroll-mt-20 overflow-hidden bg-brand-deep text-white"
    >
        <!-- Backdrop: a faint clinical grid, a mint glow and a heartbeat
             trace. Decorative only. -->
        <div
            class="lp-ai-grid pointer-events-none absolute inset-0 -z-10"
            aria-hidden="true"
        ></div>
        <div
            class="pointer-events-none absolute -top-48 left-1/2 -z-10 h-[34rem] w-[64rem] max-w-none -translate-x-1/2 rounded-full bg-brand-mint/15 blur-3xl"
            aria-hidden="true"
        ></div>
        <svg
            class="pointer-events-none absolute inset-x-0 top-24 -z-10 h-20 w-full text-brand-mint/30"
            viewBox="0 0 1200 80"
            preserveAspectRatio="none"
            fill="none"
            aria-hidden="true"
        >
            <polyline
                class="lp-ai-trace"
                points="0,50 300,50 330,50 345,40 360,50 390,50 402,58 418,6 434,72 448,50 480,50 520,38 560,50 900,50 915,40 930,50 960,50 972,58 988,6 1004,72 1018,50 1050,50 1090,38 1130,50 1200,50"
                stroke="currentColor"
                stroke-width="2"
                stroke-linejoin="round"
            />
        </svg>

        <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6 lg:py-28">
            <div class="mx-auto max-w-3xl text-center">
                <p
                    class="inline-flex items-center gap-2 rounded-full border border-brand-mint/30 bg-white/5 px-3.5 py-1.5 text-xs font-semibold text-brand-mint"
                    :class="eyebrowTracking"
                >
                    <Sparkles class="size-3.5" />
                    {{ copy.eyebrow }}
                </p>
                <h2
                    class="mt-6 text-3xl leading-tight font-bold tracking-tight text-balance sm:text-5xl"
                >
                    <span class="block">{{ copy.title }}</span>
                    <span
                        class="mt-1 block bg-gradient-to-r from-brand-mint to-teal-200 bg-clip-text pb-1 text-transparent rtl:bg-gradient-to-l"
                    >
                        {{ copy.titleAccent }}
                    </span>
                </h2>
                <p
                    class="mx-auto mt-6 max-w-2xl text-base leading-7 text-white/75 sm:text-lg sm:leading-8"
                >
                    {{ copy.subtitle }}
                </p>
            </div>

            <!-- Feature switcher. Scrolls sideways on narrow screens. -->
            <div
                class="lp-tabscroll -mx-4 mt-12 overflow-x-auto px-4 sm:mx-0 sm:px-0"
            >
                <div
                    role="tablist"
                    :aria-label="copy.hint"
                    class="mx-auto flex w-max gap-1 rounded-2xl border border-white/10 bg-white/5 p-1.5 backdrop-blur"
                >
                    <button
                        v-for="(item, index) in copy.items"
                        :id="`ai-tab-${index}`"
                        :key="item.shot"
                        type="button"
                        role="tab"
                        :aria-selected="index === active"
                        aria-controls="ai-panel"
                        :tabindex="index === active ? 0 : -1"
                        class="flex cursor-pointer items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-medium whitespace-nowrap transition"
                        :class="
                            index === active
                                ? 'bg-brand-mint text-brand-deep shadow-lg shadow-brand-mint/20'
                                : 'text-white/70 hover:bg-white/10 hover:text-white'
                        "
                        @click="active = index"
                        @keydown="moveFocus($event, index)"
                    >
                        <component
                            :is="shotIcons[item.shot]"
                            class="size-4 shrink-0"
                        />
                        {{ item.tab }}
                    </button>
                </div>
            </div>

            <div
                id="ai-panel"
                role="tabpanel"
                :aria-labelledby="`ai-tab-${active}`"
                class="mt-10 grid items-center gap-8 lg:mt-14 lg:grid-cols-[minmax(0,0.78fr)_minmax(0,1.5fr)] lg:gap-12"
            >
                <div class="order-2 lg:order-1">
                    <Transition name="lp-word" mode="out-in">
                        <div :key="activeItem.shot">
                            <span
                                class="flex size-12 items-center justify-center rounded-2xl bg-brand-mint/15 text-brand-mint ring-1 ring-brand-mint/30"
                            >
                                <component
                                    :is="shotIcons[activeItem.shot]"
                                    class="size-6"
                                />
                            </span>
                            <h3
                                class="mt-5 text-2xl leading-snug font-semibold sm:text-3xl"
                            >
                                {{ activeItem.title }}
                            </h3>
                            <p class="mt-4 text-base leading-7 text-white/75">
                                {{ activeItem.body }}
                            </p>
                        </div>
                    </Transition>

                    <!-- Position in the tour, as small progress bars. -->
                    <div class="mt-8 flex gap-1.5" aria-hidden="true">
                        <span
                            v-for="(item, index) in copy.items"
                            :key="item.shot"
                            class="h-1 rounded-full transition-all duration-500"
                            :class="
                                index === active
                                    ? 'w-10 bg-brand-mint'
                                    : 'w-4 bg-white/20'
                            "
                        ></span>
                    </div>
                </div>

                <!-- Window frame around the capture. The captures are
                     French-UI screenshots, so the frame stays LTR. -->
                <div class="relative order-1 lg:order-2" dir="ltr">
                    <div
                        class="pointer-events-none absolute -inset-6 -z-10 rounded-[2.5rem] bg-brand-mint/10 blur-2xl"
                        aria-hidden="true"
                    ></div>
                    <div
                        class="overflow-hidden rounded-2xl border border-white/15 bg-white/10 shadow-2xl shadow-black/40"
                    >
                        <div
                            class="flex items-center gap-2 border-b border-white/10 px-4 py-2.5"
                            aria-hidden="true"
                        >
                            <span
                                class="size-2.5 rounded-full bg-white/25"
                            ></span>
                            <span
                                class="size-2.5 rounded-full bg-white/25"
                            ></span>
                            <span
                                class="size-2.5 rounded-full bg-white/25"
                            ></span>
                            <span
                                class="ms-3 inline-flex items-center gap-1.5 truncate rounded-md bg-white/10 px-2.5 py-0.5 text-[11px] font-medium text-white/70"
                            >
                                <Sparkles class="size-3 text-brand-mint" />
                                Drclick · {{ activeItem.tab }}
                            </span>
                        </div>
                        <div class="relative aspect-[16/10] w-full bg-white">
                            <img
                                v-for="(item, index) in copy.items"
                                :key="item.shot"
                                :src="shotSrc(item.shot)"
                                :srcset="shotSrcset(item.shot)"
                                sizes="(min-width: 1024px) 700px, 100vw"
                                :alt="item.alt"
                                width="1600"
                                height="1000"
                                loading="lazy"
                                decoding="async"
                                class="absolute inset-0 size-full object-cover object-top transition-opacity duration-500"
                                :class="
                                    index === active
                                        ? 'opacity-100'
                                        : 'opacity-0'
                                "
                                :aria-hidden="index !== active"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Every AI action, as one hairline grid. -->
            <div class="mt-20 lg:mt-28">
                <h3
                    class="text-center text-xl font-semibold text-white sm:text-2xl"
                >
                    {{ copy.capabilitiesTitle }}
                </h3>
                <ul
                    class="mt-8 grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-white/10 bg-white/10 lg:grid-cols-4"
                >
                    <li
                        v-for="(capability, index) in copy.capabilities"
                        :key="capability.title"
                        class="group bg-brand-deep p-4 transition-colors hover:bg-[color-mix(in_oklab,var(--brand-deep),white_6%)] sm:p-6"
                    >
                        <span
                            class="flex size-10 items-center justify-center rounded-xl bg-white/5 text-brand-mint ring-1 ring-white/10 transition group-hover:ring-brand-mint/40"
                        >
                            <component
                                :is="capabilityIcons[index]"
                                class="size-5"
                            />
                        </span>
                        <p class="mt-4 font-semibold text-white">
                            {{ capability.title }}
                        </p>
                        <p class="mt-1 text-sm leading-6 text-white/65">
                            {{ capability.body }}
                        </p>
                    </li>
                </ul>
            </div>

            <!-- The rules that keep the doctor in charge. -->
            <div class="mt-16">
                <h3
                    class="flex items-center justify-center gap-3 text-xs font-semibold text-brand-mint"
                    :class="eyebrowTracking"
                >
                    <span
                        class="h-px w-8 shrink-0 bg-brand-mint/60"
                        aria-hidden="true"
                    ></span>
                    {{ copy.principlesTitle }}
                    <span
                        class="h-px w-8 shrink-0 bg-brand-mint/60"
                        aria-hidden="true"
                    ></span>
                </h3>
                <ul class="mt-8 grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
                    <li
                        v-for="(principle, index) in copy.principles"
                        :key="principle.title"
                        class="border-s-2 border-brand-mint/40 ps-4"
                    >
                        <component
                            :is="principleIcons[index]"
                            class="size-5 text-brand-mint"
                        />
                        <p class="mt-3 font-semibold text-white">
                            {{ principle.title }}
                        </p>
                        <p class="mt-1.5 text-sm leading-6 text-white/70">
                            {{ principle.body }}
                        </p>
                    </li>
                </ul>
            </div>

            <p
                class="mx-auto mt-14 flex max-w-2xl items-start justify-center gap-2 text-center text-xs leading-5 text-white/55"
            >
                <Info class="mt-0.5 size-3.5 shrink-0" />
                {{ copy.disclaimer }}
            </p>
        </div>
    </section>
</template>

<style>
/* Prefixed like the rest of the landing helpers (the page's style block is
   global once visited in an Inertia session). */
.lp-ai-grid {
    background-image:
        linear-gradient(to right, rgb(255 255 255 / 0.05) 1px, transparent 1px),
        linear-gradient(to bottom, rgb(255 255 255 / 0.05) 1px, transparent 1px);
    background-size: 48px 48px;
    mask-image: radial-gradient(ellipse at 50% 20%, black 30%, transparent 75%);
}

.lp-ai-trace {
    /* Longer than the polyline, so one dash draws the whole trace. */
    stroke-dasharray: 1700;
    stroke-dashoffset: 1700;
    animation: lp-ai-trace 6s linear infinite;
}

@keyframes lp-ai-trace {
    0% {
        stroke-dashoffset: 1700;
    }

    70%,
    100% {
        stroke-dashoffset: 0;
    }
}

@media (prefers-reduced-motion: reduce) {
    .lp-ai-trace {
        animation: none;
        stroke-dashoffset: 0;
    }
}
</style>
