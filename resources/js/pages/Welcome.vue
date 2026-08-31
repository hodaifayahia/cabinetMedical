<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    Building2,
    CalendarClock,
    Check,
    FileText,
    HardDrive,
    HeartPulse,
    Mail,
    Menu,
    Monitor,
    Phone,
    Stethoscope,
    UserCheck,
    UserCog,
    Users,
    Wifi,
    X,
} from '@lucide/vue';
import { isTauri } from '@tauri-apps/api/core';
import type { Component } from 'vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import DesktopDownloadLeadDialog from '@/components/DesktopDownloadLeadDialog.vue';
import DesktopOnboarding from '@/components/DesktopOnboarding.vue';
import DownloadButton from '@/components/landing/DownloadButton.vue';
import LanguageSwitcher from '@/components/landing/LanguageSwitcher.vue';
import PhoneMockup from '@/components/landing/PhoneMockup.vue';
import { useLandingLocale } from '@/components/landing/translations';
import {
    hasCompletedDesktopOnboarding,
    markDesktopOnboardingComplete,
} from '@/lib/desktopOnboarding';
import { dashboard, login } from '@/routes';

// `canRegister` is still provided by the home route and asserted by the
// feature test, but the public landing page intentionally exposes no
// sign-in / sign-up / join links — its only conversion goal is the download.
const props = defineProps<{
    canRegister: boolean;
    landingSections?: LandingSection[];
    landingSettings?: LandingSetting[];
}>();

type LandingSectionItem = {
    title?: string;
    body?: string;
};

type LandingSection = {
    locale: string;
    slug: string;
    section_type: string;
    eyebrow: string | null;
    title: string;
    body: string | null;
    cta_label: string | null;
    cta_url: string | null;
    image_url: string | null;
    items: LandingSectionItem[];
};

// A text managed from the platform admin panel; locale "*" applies to every
// language, otherwise the row targets one landing locale.
type LandingSetting = {
    key: string;
    locale: string;
    value: string;
};

const page = usePage();
const desktopDownload = computed(() => page.props.desktopDownload);
const desktopRuntime = ref(false);
const runtimeResolved = ref(false);
const desktopOnboardingComplete = ref(false);
const downloadDialogOpen = ref(false);
const authenticatedDesktopDestination = computed<string | null>(() => {
    if (!desktopRuntime.value || !page.props.auth.user) {
        return null;
    }

    return page.props.auth.user.can.accessAdminPanel
        ? '/admin'
        : dashboard().url;
});
const showDesktopOnboarding = computed(
    () =>
        desktopRuntime.value &&
        !page.props.auth.user &&
        !desktopOnboardingComplete.value,
);
const redirectRememberedDesktopToLogin = computed(
    () =>
        desktopRuntime.value &&
        !page.props.auth.user &&
        desktopOnboardingComplete.value,
);

const { locale, dir, copy, setLocale } = useLandingLocale();

const visibleLandingSections = computed(() =>
    (props.landingSections ?? []).filter(
        (section) => section.locale === locale.value,
    ),
);

// Admin-managed overrides: exact locale first, then the "*" fallback, then
// the built-in translated copy.
const landingSettingMap = computed(() => {
    const map = new Map<string, string>();

    for (const setting of props.landingSettings ?? []) {
        map.set(`${setting.locale}:${setting.key}`, setting.value);
    }

    return map;
});

function landingSetting(key: string): string | null {
    return (
        landingSettingMap.value.get(`${locale.value}:${key}`) ??
        landingSettingMap.value.get(`*:${key}`) ??
        null
    );
}

const requirementsTitle = computed(
    () => landingSetting('requirements_title') ?? copy.value.requirements.title,
);
const requirementsSubtitle = computed(
    () =>
        landingSetting('requirements_subtitle') ??
        copy.value.requirements.subtitle,
);
const contactPhone = computed(
    () => landingSetting('contact_phone') ?? copy.value.footer.phoneValue,
);
const contactEmail = computed(
    () => landingSetting('contact_email') ?? copy.value.footer.emailValue,
);
const contactHours = computed(
    () => landingSetting('contact_hours') ?? copy.value.footer.hoursValue,
);

const mobileNavOpen = ref(false);

// Icons paired with the six benefits, in the same order as the copy.
const benefitIcons: Component[] = [
    Users,
    CalendarClock,
    FileText,
    HeartPulse,
    UserCheck,
    HardDrive,
];

const roleIcons: Component[] = [Stethoscope, UserCog];
const requirementIcons: Component[] = [Monitor, Wifi, Building2];

// Self-hosted photography (Unsplash licence): the CSP only allows
// same-origin images, so the files live in public/images/landing/.
const photos = {
    documents: {
        src: '/images/landing/redaction-documents.webp',
        width: 1000,
        height: 750,
    },
    roles: {
        src: '/images/landing/praticien-cabinet.webp',
        width: 1000,
        height: 1250,
    },
} as const;

// Letter-spacing disconnects Arabic glyphs, so eyebrows only track in LTR.
const eyebrowTracking = computed(() =>
    locale.value === 'ar' ? '' : 'uppercase tracking-[0.16em]',
);

// Rotating keyword of the hero headline (one application / one place / …).
const rotatingIndex = ref(0);
let rotatingTimer: ReturnType<typeof setInterval> | null = null;
const rotatingWord = computed(() => {
    const words = copy.value.hero.titleRotating;

    return words[rotatingIndex.value % words.length];
});

const navLinks = computed(() => [
    { href: '#solution', label: copy.value.nav.features },
    { href: '#fonctionnement', label: copy.value.nav.how },
    { href: '#roles', label: copy.value.nav.roles },
    { href: '#telecharger', label: copy.value.nav.requirements },
    { href: '#contact', label: copy.value.nav.contact },
]);

function closeMobileNav(): void {
    mobileNavOpen.value = false;
}

function openDownloadDialog(): void {
    if (desktopDownload.value?.available) {
        downloadDialogOpen.value = true;
    }
}

let revealObserver: IntersectionObserver | null = null;

function prefersReducedMotion(): boolean {
    return (
        typeof window !== 'undefined' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

// Subtle scroll reveal. The hidden state is only applied once a working
// IntersectionObserver exists, so content can never stay invisible.
const vReveal = {
    mounted(el: HTMLElement): void {
        if (
            prefersReducedMotion() ||
            typeof IntersectionObserver === 'undefined'
        ) {
            return;
        }

        revealObserver ??= new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-revealed');
                        revealObserver?.unobserve(entry.target);
                    }
                }
            },
            // The huge top margin keeps anything at or above the viewport
            // "reached", so anchor jumps can never leave a section hidden.
            { rootMargin: '2000% 0px -8% 0px', threshold: 0.08 },
        );

        el.classList.add('lp-reveal');
        revealObserver.observe(el);
    },
    unmounted(el: HTMLElement): void {
        revealObserver?.unobserve(el);
    },
};

onMounted(() => {
    desktopRuntime.value = isTauri();

    if (desktopRuntime.value) {
        desktopOnboardingComplete.value = hasCompletedDesktopOnboarding();

        if (page.props.auth.user) {
            markDesktopOnboardingComplete();
        }
    }

    if (authenticatedDesktopDestination.value === '/admin') {
        window.location.replace('/admin');

        return;
    }

    if (authenticatedDesktopDestination.value) {
        router.visit(authenticatedDesktopDestination.value, { replace: true });

        return;
    }

    if (redirectRememberedDesktopToLogin.value) {
        router.visit(login().url, { replace: true });

        return;
    }

    runtimeResolved.value = true;

    if (desktopRuntime.value) {
        return;
    }

    if (!prefersReducedMotion()) {
        rotatingTimer = setInterval(() => {
            rotatingIndex.value =
                (rotatingIndex.value + 1) %
                copy.value.hero.titleRotating.length;
        }, 2800);
    }

    if (new URLSearchParams(window.location.search).get('download') === '1') {
        openDownloadDialog();
    }
});

onUnmounted(() => {
    if (rotatingTimer) {
        clearInterval(rotatingTimer);
    }

    revealObserver?.disconnect();
    revealObserver = null;
});
</script>

<template>
    <Head :title="copy.tagline" />

    <div
        v-if="!runtimeResolved"
        class="min-h-screen bg-slate-950"
        aria-live="polite"
        aria-label="Préparation de Drclick"
        data-test="desktop-runtime-pending"
    />

    <DesktopOnboarding
        v-else-if="showDesktopOnboarding"
        :can-register="canRegister"
    />

    <div
        v-else-if="
            authenticatedDesktopDestination || redirectRememberedDesktopToLogin
        "
        class="min-h-screen bg-slate-950"
        aria-live="polite"
        aria-label="Ouverture de votre espace"
    />

    <div
        v-else
        :dir="dir"
        :lang="locale"
        :style="
            locale === 'ar'
                ? {
                      fontFamily: `'Noto Naskh Arabic', 'Cairo', 'Segoe UI', 'Tahoma', 'Geeza Pro', 'Arial', sans-serif`,
                  }
                : undefined
        "
        class="min-h-screen bg-background text-foreground"
        :class="locale === 'ar' ? 'leading-relaxed' : ''"
    >
        <!-- Sticky header -->
        <header
            class="sticky top-0 z-40 border-b border-border/70 bg-background/90 backdrop-blur"
        >
            <div class="h-1 bg-primary" aria-hidden="true"></div>
            <div
                class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6"
            >
                <a href="#accueil" class="flex items-center gap-2.5">
                    <AppLogoIcon class="size-10 object-contain" />
                    <span class="flex flex-col leading-tight">
                        <span class="text-base font-bold tracking-tight"
                            >Drclick</span
                        >
                        <span
                            class="hidden text-[11px] text-muted-foreground sm:block"
                        >
                            {{ copy.tagline }}
                        </span>
                    </span>
                </a>

                <nav class="hidden items-center gap-7 lg:flex">
                    <a
                        v-for="link in navLinks"
                        :key="link.href"
                        :href="link.href"
                        class="text-sm font-medium whitespace-nowrap text-muted-foreground decoration-primary decoration-2 underline-offset-8 transition hover:text-foreground hover:underline"
                    >
                        {{ link.label }}
                    </a>
                </nav>

                <div class="flex items-center gap-2 sm:gap-3">
                    <LanguageSwitcher
                        :locale="locale"
                        :label="copy.switcherLabel"
                        @update:locale="setLocale"
                    />
                    <div class="hidden sm:block">
                        <DownloadButton
                            :available="desktopDownload?.available ?? false"
                            :url="desktopDownload?.url ?? null"
                            :reason="desktopDownload?.reason ?? null"
                            :label="copy.download.cta"
                            :unavailable-label="copy.download.unavailable"
                            @click.prevent="openDownloadDialog"
                        />
                    </div>
                    <button
                        type="button"
                        class="flex size-11 cursor-pointer items-center justify-center rounded-xl border border-border text-foreground transition hover:bg-muted lg:hidden"
                        :aria-label="copy.nav.menuLabel"
                        :aria-expanded="mobileNavOpen"
                        @click="mobileNavOpen = !mobileNavOpen"
                    >
                        <X v-if="mobileNavOpen" class="size-5" />
                        <Menu v-else class="size-5" />
                    </button>
                </div>
            </div>

            <!-- Mobile nav -->
            <div
                v-if="mobileNavOpen"
                class="border-t border-border bg-background px-4 py-4 lg:hidden"
            >
                <nav class="flex flex-col gap-1">
                    <a
                        v-for="link in navLinks"
                        :key="link.href"
                        :href="link.href"
                        class="rounded-lg px-3 py-2.5 text-sm font-medium text-muted-foreground transition hover:bg-muted hover:text-foreground"
                        @click="closeMobileNav"
                    >
                        {{ link.label }}
                    </a>
                </nav>
                <div class="mt-3 border-t border-border pt-3">
                    <DownloadButton
                        :available="desktopDownload?.available ?? false"
                        :url="desktopDownload?.url ?? null"
                        :reason="desktopDownload?.reason ?? null"
                        :label="copy.download.cta"
                        :unavailable-label="copy.download.unavailable"
                        class="w-full"
                        @click.prevent="openDownloadDialog"
                    />
                </div>
            </div>
        </header>

        <main id="accueil" class="scroll-mt-20">
            <!-- Hero -->
            <section class="relative overflow-hidden">
                <div
                    class="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[26rem] border-b border-border/50 bg-brand-soft/25"
                    aria-hidden="true"
                ></div>
                <div
                    class="mx-auto max-w-6xl px-4 pt-16 pb-14 sm:px-6 lg:pt-24 lg:pb-16"
                >
                    <div class="mx-auto max-w-3xl text-center">
                        <p
                            class="inline-flex items-center gap-3 text-xs font-semibold text-primary"
                            :class="eyebrowTracking"
                        >
                            <span
                                class="h-px w-8 shrink-0 bg-primary"
                                aria-hidden="true"
                            ></span>
                            {{ copy.hero.eyebrow }}
                            <span
                                class="h-px w-8 shrink-0 bg-primary"
                                aria-hidden="true"
                            ></span>
                        </p>

                        <h1
                            class="mt-7 text-[2.6rem] leading-[1.08] font-bold tracking-tight sm:text-6xl lg:text-7xl"
                        >
                            <span class="block text-foreground">
                                {{ copy.hero.titleLead }}
                            </span>
                            <span
                                class="mt-2 block text-primary"
                                :class="
                                    locale === 'fr'
                                        ? 'min-h-[2.3em] sm:min-h-[1.15em]'
                                        : 'min-h-[1.15em]'
                                "
                            >
                                <Transition name="lp-word" mode="out-in">
                                    <span
                                        :key="rotatingWord"
                                        class="relative inline-block px-2"
                                    >
                                        <span
                                            class="absolute inset-x-0 bottom-[0.06em] -z-10 h-[0.32em] rounded-sm bg-brand-soft"
                                            aria-hidden="true"
                                        ></span>
                                        {{ rotatingWord }}
                                    </span>
                                </Transition>
                            </span>
                        </h1>

                        <p
                            class="mx-auto mt-7 max-w-2xl text-base leading-7 text-muted-foreground sm:text-lg sm:leading-8"
                        >
                            {{ copy.hero.subtitle }}
                        </p>

                        <div class="mt-9 flex flex-col items-center gap-3">
                            <DownloadButton
                                data-testid="open-desktop-download-form"
                                :available="desktopDownload?.available ?? false"
                                :url="desktopDownload?.url ?? null"
                                :reason="desktopDownload?.reason ?? null"
                                :label="copy.download.cta"
                                :unavailable-label="copy.download.unavailable"
                                size="lg"
                                @click.prevent="openDownloadDialog"
                            />
                            <p class="text-sm text-muted-foreground">
                                {{ copy.download.note }}
                            </p>
                        </div>

                        <ul
                            class="mt-10 flex flex-wrap items-center justify-center gap-x-7 gap-y-3"
                        >
                            <li
                                v-for="item in copy.hero.highlights"
                                :key="item"
                                class="flex items-center gap-2.5 text-sm leading-6 font-medium text-foreground"
                            >
                                <span
                                    class="flex size-5 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                                >
                                    <Check class="size-3.5" />
                                </span>
                                {{ item }}
                            </li>
                        </ul>
                    </div>

                    <!-- Facts strip -->
                    <div
                        class="mt-14 grid gap-x-8 gap-y-6 border-t border-border pt-8 sm:grid-cols-3 lg:mt-20"
                    >
                        <div
                            v-for="(stat, index) in copy.hero.stats"
                            :key="stat.label"
                            v-reveal
                            class="flex items-baseline justify-center gap-3"
                            :style="{
                                '--lp-reveal-delay': `${index * 90}ms`,
                            }"
                        >
                            <span
                                class="text-4xl font-bold tracking-tight text-primary tabular-nums sm:text-5xl"
                            >
                                {{ stat.value }}
                            </span>
                            <span
                                class="max-w-[12rem] text-sm leading-5 text-muted-foreground"
                            >
                                {{ stat.label }}
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Benefits ledger -->
            <section
                id="solution"
                class="scroll-mt-20 border-y border-border bg-card"
            >
                <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:py-24">
                    <div
                        class="grid gap-12 lg:grid-cols-[0.8fr_1.2fr] lg:gap-16"
                    >
                        <div class="lg:sticky lg:top-28 lg:self-start">
                            <p
                                class="flex items-center gap-3 text-xs font-semibold text-primary"
                                :class="eyebrowTracking"
                            >
                                <span
                                    class="h-px w-8 shrink-0 bg-primary"
                                    aria-hidden="true"
                                ></span>
                                {{ copy.benefits.eyebrow }}
                            </p>
                            <h2
                                class="mt-4 text-3xl font-bold tracking-tight text-foreground sm:text-4xl"
                            >
                                {{ copy.benefits.title }}
                            </h2>
                            <p
                                class="mt-4 text-base leading-7 text-muted-foreground"
                            >
                                {{ copy.benefits.subtitle }}
                            </p>
                            <img
                                :src="photos.documents.src"
                                :alt="copy.photos.documents"
                                :width="photos.documents.width"
                                :height="photos.documents.height"
                                loading="lazy"
                                class="mt-10 hidden aspect-[4/3] w-full rounded-2xl border border-border object-cover lg:block"
                            />
                        </div>

                        <div>
                            <ul
                                class="sr-only"
                                aria-label="Fonctionnalités Drclick"
                            >
                                <li>Dossiers patients</li>
                                <li>Agenda du cabinet</li>
                                <li>Consultation structurée</li>
                                <li>Ordonnances et documents</li>
                                <li>Paiements lisibles</li>
                                <li>Équipe et accès contrôlés</li>
                            </ul>

                            <ol class="border-b border-border">
                                <li
                                    v-for="(benefit, index) in copy.benefits
                                        .items"
                                    :key="benefit.title"
                                    v-reveal
                                    class="group grid grid-cols-[2.25rem_auto_1fr] items-start gap-x-4 rounded-xl border-t border-border px-3 py-6 transition-colors hover:bg-accent/40 sm:gap-x-6 sm:px-4 lg:py-7"
                                    :style="{
                                        '--lp-reveal-delay': `${(index % 3) * 60}ms`,
                                    }"
                                >
                                    <span
                                        class="pt-2.5 font-mono text-xs font-semibold text-primary/70 tabular-nums"
                                    >
                                        0{{ index + 1 }}
                                    </span>
                                    <span
                                        class="flex size-11 items-center justify-center rounded-xl bg-brand-soft/70 text-primary transition-colors group-hover:bg-brand-soft"
                                    >
                                        <component
                                            :is="benefitIcons[index]"
                                            class="size-5"
                                        />
                                    </span>
                                    <div class="min-w-0">
                                        <h3
                                            class="text-lg font-semibold text-foreground"
                                        >
                                            {{ benefit.title }}
                                        </h3>
                                        <p
                                            class="mt-1.5 text-sm leading-6 text-muted-foreground"
                                        >
                                            {{ benefit.body }}
                                        </p>
                                    </div>
                                </li>
                            </ol>

                            <img
                                :src="photos.documents.src"
                                :alt="copy.photos.documents"
                                :width="photos.documents.width"
                                :height="photos.documents.height"
                                loading="lazy"
                                class="mt-10 aspect-[16/9] w-full rounded-xl border border-border object-cover lg:hidden"
                            />
                        </div>
                    </div>
                </div>
            </section>

            <!-- How it works -->
            <section id="fonctionnement" class="scroll-mt-20">
                <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:py-24">
                    <div
                        class="grid gap-6 lg:grid-cols-[1.1fr_0.9fr] lg:items-end lg:gap-16"
                    >
                        <div>
                            <p
                                class="flex items-center gap-3 text-xs font-semibold text-primary"
                                :class="eyebrowTracking"
                            >
                                <span
                                    class="h-px w-8 shrink-0 bg-primary"
                                    aria-hidden="true"
                                ></span>
                                {{ copy.how.eyebrow }}
                            </p>
                            <h2
                                class="mt-4 max-w-xl text-3xl font-bold tracking-tight text-foreground sm:text-4xl"
                            >
                                {{ copy.how.title }}
                            </h2>
                        </div>
                        <p
                            class="text-base leading-7 text-muted-foreground lg:pb-1.5"
                        >
                            {{ copy.how.subtitle }}
                        </p>
                    </div>

                    <ol
                        class="mt-12 grid gap-10 md:grid-cols-3 md:gap-8 lg:mt-16"
                    >
                        <li
                            v-for="(step, index) in copy.how.steps"
                            :key="step.title"
                            v-reveal
                            class="border-s-2 border-primary/20 ps-5 md:border-t-2 md:border-s-0 md:ps-0 md:pt-6"
                            :class="{
                                'md:mt-10': index === 1,
                                'md:mt-20': index === 2,
                            }"
                            :style="{
                                '--lp-reveal-delay': `${index * 90}ms`,
                            }"
                        >
                            <span
                                class="block text-6xl leading-none font-bold tracking-tight text-primary/15 select-none lg:text-7xl"
                                aria-hidden="true"
                            >
                                {{ index + 1 }}
                            </span>
                            <h3
                                class="mt-4 text-xl font-semibold text-foreground"
                            >
                                {{ step.title }}
                            </h3>
                            <p
                                class="mt-2 max-w-sm text-sm leading-6 text-muted-foreground"
                            >
                                {{ step.body }}
                            </p>
                        </li>
                    </ol>
                </div>
            </section>

            <!-- Roles -->
            <section
                id="roles"
                class="scroll-mt-20 border-y border-border bg-card"
            >
                <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:py-24">
                    <div
                        class="grid gap-10 lg:grid-cols-[0.85fr_1.15fr] lg:gap-16"
                    >
                        <div class="lg:sticky lg:top-28 lg:self-start">
                            <img
                                :src="photos.roles.src"
                                :alt="copy.photos.roles"
                                :width="photos.roles.width"
                                :height="photos.roles.height"
                                loading="lazy"
                                class="aspect-[3/2] w-full rounded-2xl border border-border object-cover lg:aspect-[4/5]"
                            />
                        </div>

                        <div>
                            <p
                                class="flex items-center gap-3 text-xs font-semibold text-primary"
                                :class="eyebrowTracking"
                            >
                                <span
                                    class="h-px w-8 shrink-0 bg-primary"
                                    aria-hidden="true"
                                ></span>
                                {{ copy.roles.eyebrow }}
                            </p>
                            <h2
                                class="mt-4 text-3xl font-bold tracking-tight text-foreground sm:text-4xl"
                            >
                                {{ copy.roles.title }}
                            </h2>
                            <p
                                class="mt-4 max-w-2xl text-base leading-7 text-muted-foreground"
                            >
                                {{ copy.roles.subtitle }}
                            </p>

                            <div class="mt-10 space-y-6">
                                <article
                                    v-for="(role, index) in copy.roles.items"
                                    :key="role.title"
                                    v-reveal
                                    class="rounded-2xl border border-border bg-background p-6 sm:p-7"
                                    :style="{
                                        '--lp-reveal-delay': `${index * 90}ms`,
                                    }"
                                >
                                    <div class="flex items-center gap-3.5">
                                        <span
                                            class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-soft/70 text-primary"
                                        >
                                            <component
                                                :is="roleIcons[index]"
                                                class="size-5"
                                            />
                                        </span>
                                        <div>
                                            <h3
                                                class="text-lg font-semibold text-foreground"
                                            >
                                                {{ role.title }}
                                            </h3>
                                            <p
                                                class="text-sm text-muted-foreground"
                                            >
                                                {{ role.body }}
                                            </p>
                                        </div>
                                    </div>
                                    <ul
                                        class="mt-5 grid gap-2.5 border-t border-border pt-5 sm:grid-cols-2 sm:gap-3"
                                    >
                                        <li
                                            v-for="point in role.points"
                                            :key="point"
                                            class="flex items-start gap-2.5 text-sm leading-6 text-foreground"
                                        >
                                            <span
                                                class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                                            >
                                                <Check class="size-3.5" />
                                            </span>
                                            {{ point }}
                                        </li>
                                    </ul>
                                </article>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Upcoming patient mobile app -->
            <section id="application-mobile" class="scroll-mt-20">
                <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:py-24">
                    <div
                        class="grid items-center gap-12 lg:grid-cols-[1.1fr_0.9fr] lg:gap-16"
                    >
                        <div>
                            <span
                                class="inline-flex items-center gap-2.5 rounded-full border border-primary/25 bg-brand-soft/60 px-3.5 py-1.5 text-xs font-semibold text-accent-foreground"
                            >
                                <span
                                    class="relative flex size-2"
                                    aria-hidden="true"
                                >
                                    <span
                                        class="lp-ping absolute inline-flex h-full w-full rounded-full bg-primary opacity-60"
                                    ></span>
                                    <span
                                        class="relative inline-flex size-2 rounded-full bg-primary"
                                    ></span>
                                </span>
                                {{ copy.mobileApp.badge }}
                            </span>
                            <h2
                                class="mt-5 text-3xl font-bold tracking-tight text-foreground sm:text-4xl"
                            >
                                {{ copy.mobileApp.title }}
                            </h2>
                            <p
                                class="mt-4 max-w-xl text-base leading-7 text-muted-foreground"
                            >
                                {{ copy.mobileApp.body }}
                            </p>
                            <ul class="mt-8 space-y-3">
                                <li
                                    v-for="point in copy.mobileApp.points"
                                    :key="point"
                                    class="flex items-start gap-2.5 text-sm leading-6 font-medium text-foreground"
                                >
                                    <span
                                        class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                                    >
                                        <Check class="size-3.5" />
                                    </span>
                                    {{ point }}
                                </li>
                            </ul>
                        </div>

                        <div v-reveal class="mx-auto lg:mx-0 lg:justify-self-center">
                            <div class="lp-float">
                                <PhoneMockup :locale="locale" />
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Managed landing content -->
            <section
                v-for="section in visibleLandingSections"
                :id="`landing-${section.slug}`"
                :key="`${section.locale}-${section.slug}`"
                class="border-t border-border"
                :class="
                    section.section_type === 'cta'
                        ? 'bg-primary text-primary-foreground'
                        : 'bg-background'
                "
            >
                <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:py-20">
                    <div class="max-w-3xl">
                        <p
                            v-if="section.eyebrow"
                            class="flex items-center gap-3 text-xs font-semibold"
                            :class="[
                                eyebrowTracking,
                                section.section_type === 'cta'
                                    ? 'text-primary-foreground/85'
                                    : 'text-primary',
                            ]"
                        >
                            <span
                                class="h-px w-8 shrink-0"
                                :class="
                                    section.section_type === 'cta'
                                        ? 'bg-primary-foreground/60'
                                        : 'bg-primary'
                                "
                                aria-hidden="true"
                            ></span>
                            {{ section.eyebrow }}
                        </p>
                        <h2
                            class="mt-4 text-3xl font-bold tracking-tight sm:text-4xl"
                            :class="
                                section.section_type === 'cta'
                                    ? 'text-primary-foreground'
                                    : 'text-foreground'
                            "
                        >
                            {{ section.title }}
                        </h2>
                        <p
                            v-if="section.body"
                            class="mt-4 text-base leading-7 whitespace-pre-line"
                            :class="
                                section.section_type === 'cta'
                                    ? 'text-primary-foreground/85'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{ section.body }}
                        </p>
                    </div>

                    <div
                        v-if="section.items.length"
                        class="mt-10 grid gap-4 md:grid-cols-3"
                    >
                        <article
                            v-for="(item, index) in section.items"
                            :key="`${section.slug}-${index}`"
                            class="rounded-2xl border border-border bg-card p-6"
                        >
                            <h3 class="font-semibold text-card-foreground">
                                {{ item.title }}
                            </h3>
                            <p
                                v-if="item.body"
                                class="mt-2 text-sm leading-6 text-muted-foreground"
                            >
                                {{ item.body }}
                            </p>
                        </article>
                    </div>

                    <img
                        v-if="section.image_url"
                        :src="section.image_url"
                        :alt="section.title"
                        loading="lazy"
                        class="mt-10 aspect-[21/9] w-full rounded-2xl border border-border object-cover"
                    />

                    <a
                        v-if="section.cta_label && section.cta_url"
                        :href="section.cta_url"
                        class="mt-8 inline-flex rounded-xl px-5 py-3 text-sm font-semibold shadow-sm transition"
                        :class="
                            section.section_type === 'cta'
                                ? 'bg-card text-brand-deep hover:bg-card/90'
                                : 'bg-primary text-primary-foreground hover:bg-primary/90'
                        "
                    >
                        {{ section.cta_label }}
                    </a>
                </div>
            </section>

            <!-- Requirements & practical info -->
            <section
                id="telecharger"
                class="scroll-mt-20 border-t border-border bg-card"
            >
                <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:py-24">
                    <div class="grid gap-12 lg:grid-cols-2 lg:gap-16">
                        <div>
                            <p
                                class="flex items-center gap-3 text-xs font-semibold text-primary"
                                :class="eyebrowTracking"
                            >
                                <span
                                    class="h-px w-8 shrink-0 bg-primary"
                                    aria-hidden="true"
                                ></span>
                                {{ copy.requirements.eyebrow }}
                            </p>
                            <h2
                                class="mt-4 text-3xl font-bold tracking-tight text-foreground sm:text-4xl"
                            >
                                {{ requirementsTitle }}
                            </h2>
                            <p
                                class="mt-4 max-w-md text-base leading-7 text-muted-foreground"
                            >
                                {{ requirementsSubtitle }}
                            </p>

                            <ul class="mt-10 border-t border-border">
                                <li
                                    class="flex items-center justify-between gap-6 border-b border-border py-4"
                                >
                                    <span
                                        class="flex items-center gap-3 text-sm text-muted-foreground"
                                    >
                                        <Phone
                                            class="size-4 shrink-0 text-primary"
                                        />
                                        {{ copy.footer.phoneLabel }}
                                    </span>
                                    <span
                                        class="text-sm font-semibold text-foreground"
                                        dir="ltr"
                                    >
                                        {{ contactPhone }}
                                    </span>
                                </li>
                                <li
                                    class="flex items-center justify-between gap-6 border-b border-border py-4"
                                >
                                    <span
                                        class="flex items-center gap-3 text-sm text-muted-foreground"
                                    >
                                        <Mail
                                            class="size-4 shrink-0 text-primary"
                                        />
                                        {{ copy.footer.emailLabel }}
                                    </span>
                                    <span
                                        class="text-sm font-semibold text-foreground"
                                        dir="ltr"
                                    >
                                        {{ contactEmail }}
                                    </span>
                                </li>
                                <li
                                    class="flex items-center justify-between gap-6 border-b border-border py-4"
                                >
                                    <span
                                        class="flex items-center gap-3 text-sm text-muted-foreground"
                                    >
                                        <CalendarClock
                                            class="size-4 shrink-0 text-primary"
                                        />
                                        {{ copy.footer.hoursLabel }}
                                    </span>
                                    <span
                                        class="text-end text-sm font-semibold text-foreground"
                                    >
                                        {{ contactHours }}
                                    </span>
                                </li>
                            </ul>
                        </div>

                        <div class="lg:pt-2">
                            <div
                                class="rounded-2xl border border-border bg-background p-6 sm:p-8"
                            >
                                <ul class="space-y-5">
                                    <li
                                        v-for="(item, index) in copy
                                            .requirements.items"
                                        :key="item"
                                        class="flex items-center gap-4"
                                    >
                                        <span
                                            class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-soft/70 text-primary"
                                        >
                                            <component
                                                :is="requirementIcons[index]"
                                                class="size-5"
                                            />
                                        </span>
                                        <span
                                            class="text-sm leading-6 font-medium text-foreground"
                                        >
                                            {{ item }}
                                        </span>
                                    </li>
                                </ul>
                                <div class="mt-8 border-t border-border pt-6">
                                    <DownloadButton
                                        :available="
                                            desktopDownload?.available ?? false
                                        "
                                        :url="desktopDownload?.url ?? null"
                                        :reason="
                                            desktopDownload?.reason ?? null
                                        "
                                        :label="copy.download.cta"
                                        :unavailable-label="
                                            copy.download.unavailable
                                        "
                                        class="w-full sm:w-auto"
                                        @click.prevent="openDownloadDialog"
                                    />
                                    <p
                                        class="mt-3 text-sm leading-5 text-muted-foreground"
                                    >
                                        {{ copy.download.note }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Download call to action -->
            <section class="border-t border-border">
                <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:py-20">
                    <div
                        v-reveal
                        class="rounded-3xl bg-primary px-6 py-10 text-primary-foreground sm:px-10 sm:py-12 lg:px-14"
                    >
                        <span
                            class="block h-1 w-14 rounded-full bg-primary-foreground/40"
                            aria-hidden="true"
                        ></span>
                        <div
                            class="mt-6 grid items-center gap-8 lg:grid-cols-[1fr_auto] lg:gap-12"
                        >
                            <div>
                                <h2
                                    class="max-w-2xl text-3xl font-bold tracking-tight sm:text-4xl"
                                >
                                    {{ copy.hero.title }}
                                </h2>
                                <p
                                    class="mt-4 max-w-xl text-sm leading-6 text-primary-foreground/85 sm:text-base"
                                >
                                    {{ copy.download.note }}
                                </p>
                            </div>
                            <DownloadButton
                                :available="desktopDownload?.available ?? false"
                                :url="desktopDownload?.url ?? null"
                                :reason="desktopDownload?.reason ?? null"
                                :label="copy.download.cta"
                                :unavailable-label="copy.download.unavailable"
                                variant="inverse"
                                size="lg"
                                @click.prevent="openDownloadDialog"
                            />
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <!-- Footer -->
        <footer id="contact" class="scroll-mt-20 bg-brand-deep text-white">
            <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6 lg:py-16">
                <div class="grid gap-10 md:grid-cols-[1.2fr_1fr]">
                    <div>
                        <div class="flex items-center gap-2.5">
                            <span
                                class="flex size-10 items-center justify-center rounded-xl bg-white/95 p-1"
                            >
                                <AppLogoIcon class="size-8 object-contain" />
                            </span>
                            <span class="text-base font-bold tracking-tight"
                                >Drclick</span
                            >
                        </div>
                        <p
                            class="mt-5 max-w-sm text-sm leading-6 text-white/70"
                        >
                            {{ copy.footer.blurb }}
                        </p>
                        <nav class="mt-8 flex flex-wrap gap-x-6 gap-y-2">
                            <a
                                v-for="link in navLinks"
                                :key="link.href"
                                :href="link.href"
                                class="text-sm text-white/70 transition hover:text-white"
                            >
                                {{ link.label }}
                            </a>
                        </nav>
                    </div>

                    <div>
                        <h2
                            class="text-xs font-semibold text-white/60"
                            :class="eyebrowTracking"
                        >
                            {{ copy.footer.contactTitle }}
                        </h2>
                        <ul class="mt-5 space-y-4 text-sm text-white/80">
                            <li class="flex items-center gap-3">
                                <Phone
                                    class="size-4 shrink-0 text-brand-mint"
                                />
                                <span dir="ltr">{{ contactPhone }}</span>
                            </li>
                            <li class="flex items-center gap-3">
                                <Mail
                                    class="size-4 shrink-0 text-brand-mint"
                                />
                                <span dir="ltr">{{ contactEmail }}</span>
                            </li>
                            <li class="flex items-start gap-3">
                                <CalendarClock
                                    class="mt-0.5 size-4 shrink-0 text-brand-mint"
                                />
                                <span>{{ contactHours }}</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div
                    class="mt-12 flex flex-col gap-2 border-t border-white/15 pt-6 text-xs text-white/60 sm:flex-row sm:items-center sm:justify-between"
                >
                    <span>
                        &copy; {{ new Date().getFullYear() }}
                        {{ copy.footer.rights }}
                    </span>
                    <span>{{ copy.tagline }}</span>
                </div>
            </div>
        </footer>

        <DesktopDownloadLeadDialog
            v-if="!desktopRuntime"
            v-model:open="downloadDialogOpen"
            :available="desktopDownload?.available ?? false"
            action="/desktop/download"
            :label="desktopDownload?.label ?? copy.download.cta"
            :reason="desktopDownload?.reason ?? null"
        />
    </div>
</template>

<style>
/* Landing-only helpers, prefixed to stay collision-free (this style block
   is global once the page has been visited in an Inertia session). */
.lp-reveal {
    opacity: 0;
    transform: translateY(18px);
    transition:
        opacity 0.6s cubic-bezier(0.22, 1, 0.36, 1),
        transform 0.6s cubic-bezier(0.22, 1, 0.36, 1);
    transition-delay: var(--lp-reveal-delay, 0ms);
}

.lp-reveal.is-revealed {
    opacity: 1;
    transform: none;
}

/* Rotating hero keyword. */
.lp-word-enter-active {
    transition:
        opacity 0.4s ease,
        transform 0.4s cubic-bezier(0.22, 1, 0.36, 1);
}

.lp-word-leave-active {
    transition:
        opacity 0.28s ease,
        transform 0.28s ease;
}

.lp-word-enter-from {
    opacity: 0;
    transform: translateY(0.55em);
}

.lp-word-leave-to {
    opacity: 0;
    transform: translateY(-0.45em);
}

@keyframes lp-float {
    0%,
    100% {
        transform: translateY(0);
    }

    50% {
        transform: translateY(-9px);
    }
}

.lp-float {
    animation: lp-float 8s ease-in-out infinite;
}

@keyframes lp-ping {
    0% {
        transform: scale(1);
        opacity: 0.6;
    }

    70%,
    100% {
        transform: scale(2.4);
        opacity: 0;
    }
}

.lp-ping {
    animation: lp-ping 1.8s cubic-bezier(0, 0, 0.2, 1) infinite;
}

@media (prefers-reduced-motion: reduce) {
    .lp-reveal {
        opacity: 1;
        transform: none;
        transition: none;
    }

    .lp-word-enter-active,
    .lp-word-leave-active {
        transition: none;
    }

    .lp-float,
    .lp-ping {
        animation: none;
    }
}
</style>
