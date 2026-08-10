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
} from '@lucide/vue';
import { isTauri } from '@tauri-apps/api/core';
import type { Component } from 'vue';
import { computed, onMounted, ref } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import DesktopDownloadLeadDialog from '@/components/DesktopDownloadLeadDialog.vue';
import DesktopOnboarding from '@/components/DesktopOnboarding.vue';
import AppMockup from '@/components/landing/AppMockup.vue';
import DownloadButton from '@/components/landing/DownloadButton.vue';
import LanguageSwitcher from '@/components/landing/LanguageSwitcher.vue';
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

    if (new URLSearchParams(window.location.search).get('download') === '1') {
        openDownloadDialog();
    }
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
            class="sticky top-0 z-40 border-b border-border/70 bg-background/85 backdrop-blur"
        >
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

                <nav class="hidden items-center gap-6 lg:flex">
                    <a
                        v-for="link in navLinks"
                        :key="link.href"
                        :href="link.href"
                        class="text-sm font-medium text-muted-foreground transition hover:text-foreground"
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
                        class="flex size-10 items-center justify-center rounded-xl border border-border text-foreground transition hover:bg-muted lg:hidden"
                        :aria-label="copy.nav.features"
                        :aria-expanded="mobileNavOpen"
                        @click="mobileNavOpen = !mobileNavOpen"
                    >
                        <Menu class="size-5" />
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
                <div class="mt-3">
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

        <main id="accueil">
            <!-- Hero -->
            <section class="relative overflow-hidden">
                <div
                    class="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[420px] bg-brand-soft/50"
                ></div>
                <div
                    class="mx-auto grid max-w-6xl items-center gap-12 px-4 py-16 sm:px-6 lg:grid-cols-[1.05fr_0.95fr] lg:py-24"
                >
                    <div>
                        <span
                            class="inline-flex items-center gap-2 rounded-full border border-border bg-accent/60 px-3 py-1 text-xs font-semibold text-accent-foreground"
                        >
                            <HeartPulse class="size-3.5" />
                            {{ copy.hero.eyebrow }}
                        </span>
                        <h1
                            class="mt-5 text-4xl font-black tracking-tight text-foreground sm:text-5xl lg:text-[3.4rem] lg:leading-[1.08]"
                        >
                            {{ copy.hero.title }}
                        </h1>
                        <p
                            class="mt-5 max-w-xl text-base leading-7 text-muted-foreground sm:text-lg"
                        >
                            {{ copy.hero.subtitle }}
                        </p>

                        <ul class="mt-6 flex flex-col gap-2.5">
                            <li
                                v-for="item in copy.hero.highlights"
                                :key="item"
                                class="flex items-center gap-2.5 text-sm font-medium text-foreground"
                            >
                                <span
                                    class="flex size-5 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                                >
                                    <Check class="size-3.5" />
                                </span>
                                {{ item }}
                            </li>
                        </ul>

                        <div
                            class="mt-8 flex flex-col items-start gap-3 sm:flex-row sm:items-center"
                        >
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
                    </div>

                    <div class="relative">
                        <AppMockup :locale="locale" />
                    </div>
                </div>
            </section>

            <!-- Benefits -->
            <section id="solution" class="border-t border-border bg-card">
                <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:py-20">
                    <div class="max-w-2xl">
                        <p
                            class="text-sm font-semibold tracking-wide text-primary uppercase"
                        >
                            {{ copy.benefits.eyebrow }}
                        </p>
                        <h2
                            class="mt-2 text-3xl font-bold tracking-tight sm:text-4xl"
                        >
                            {{ copy.benefits.title }}
                        </h2>
                        <p
                            class="mt-3 text-base leading-7 text-muted-foreground"
                        >
                            {{ copy.benefits.subtitle }}
                        </p>
                    </div>
                    <ul class="sr-only" aria-label="Fonctionnalités Drclick">
                        <li>Dossiers patients</li>
                        <li>Agenda du cabinet</li>
                        <li>Consultation structurée</li>
                        <li>Ordonnances et documents</li>
                        <li>Paiements lisibles</li>
                        <li>Équipe et accès contrôlés</li>
                    </ul>

                    <div
                        class="mt-12 grid gap-px overflow-hidden rounded-2xl border border-border bg-border sm:grid-cols-2 lg:grid-cols-3"
                    >
                        <article
                            v-for="(benefit, index) in copy.benefits.items"
                            :key="benefit.title"
                            class="bg-card p-6 transition hover:bg-accent/30"
                        >
                            <span
                                class="flex size-11 items-center justify-center rounded-xl bg-primary/10 text-primary"
                            >
                                <component
                                    :is="benefitIcons[index]"
                                    class="size-5"
                                />
                            </span>
                            <h3
                                class="mt-4 text-lg font-semibold text-foreground"
                            >
                                {{ benefit.title }}
                            </h3>
                            <p
                                class="mt-2 text-sm leading-6 text-muted-foreground"
                            >
                                {{ benefit.body }}
                            </p>
                        </article>
                    </div>
                </div>
            </section>

            <!-- How it works -->
            <section id="fonctionnement" class="border-t border-border">
                <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:py-20">
                    <div class="max-w-2xl">
                        <p
                            class="text-sm font-semibold tracking-wide text-primary uppercase"
                        >
                            {{ copy.how.eyebrow }}
                        </p>
                        <h2
                            class="mt-2 text-3xl font-bold tracking-tight sm:text-4xl"
                        >
                            {{ copy.how.title }}
                        </h2>
                        <p
                            class="mt-3 text-base leading-7 text-muted-foreground"
                        >
                            {{ copy.how.subtitle }}
                        </p>
                    </div>

                    <ol class="mt-12 grid gap-6 md:grid-cols-3">
                        <li
                            v-for="(step, index) in copy.how.steps"
                            :key="step.title"
                            class="relative rounded-2xl border border-border bg-card p-6"
                        >
                            <span
                                class="flex size-10 items-center justify-center rounded-full border border-primary/30 bg-primary/5 text-lg font-bold text-primary"
                            >
                                {{ index + 1 }}
                            </span>
                            <h3
                                class="mt-4 text-lg font-semibold text-foreground"
                            >
                                {{ step.title }}
                            </h3>
                            <p
                                class="mt-2 text-sm leading-6 text-muted-foreground"
                            >
                                {{ step.body }}
                            </p>
                        </li>
                    </ol>
                </div>
            </section>

            <!-- Roles -->
            <section id="roles" class="border-t border-border bg-card">
                <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:py-20">
                    <div class="max-w-2xl">
                        <p
                            class="text-sm font-semibold tracking-wide text-primary uppercase"
                        >
                            {{ copy.roles.eyebrow }}
                        </p>
                        <h2
                            class="mt-2 text-3xl font-bold tracking-tight sm:text-4xl"
                        >
                            {{ copy.roles.title }}
                        </h2>
                        <p
                            class="mt-3 text-base leading-7 text-muted-foreground"
                        >
                            {{ copy.roles.subtitle }}
                        </p>
                    </div>

                    <div class="mt-12 grid gap-6 md:grid-cols-2">
                        <article
                            v-for="(role, index) in copy.roles.items"
                            :key="role.title"
                            class="rounded-2xl border border-border bg-background p-7"
                        >
                            <div class="flex items-center gap-3">
                                <span
                                    class="flex size-11 items-center justify-center rounded-xl bg-primary/10 text-primary"
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
                                    <p class="text-sm text-muted-foreground">
                                        {{ role.body }}
                                    </p>
                                </div>
                            </div>
                            <ul
                                class="mt-5 space-y-2.5 border-t border-border pt-5"
                            >
                                <li
                                    v-for="point in role.points"
                                    :key="point"
                                    class="flex items-start gap-2.5 text-sm text-foreground"
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
                            class="text-sm font-semibold tracking-wide text-primary uppercase"
                            :class="
                                section.section_type === 'cta'
                                    ? 'text-primary-foreground/80'
                                    : ''
                            "
                        >
                            {{ section.eyebrow }}
                        </p>
                        <h2
                            class="mt-2 text-3xl font-bold tracking-tight sm:text-4xl"
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
                            <h3 class="font-semibold text-foreground">
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
                        class="mt-10 max-h-80 w-full rounded-2xl border border-border object-cover"
                    />

                    <a
                        v-if="section.cta_label && section.cta_url"
                        :href="section.cta_url"
                        class="mt-8 inline-flex rounded-xl bg-primary px-5 py-3 text-sm font-semibold text-primary-foreground shadow-sm transition hover:opacity-90"
                    >
                        {{ section.cta_label }}
                    </a>
                </div>
            </section>

            <!-- System requirements -->
            <section id="telecharger" class="border-t border-border">
                <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:py-20">
                    <div
                        class="grid gap-10 lg:grid-cols-[0.9fr_1.1fr] lg:items-center"
                    >
                        <div class="max-w-md">
                            <p
                                class="text-sm font-semibold tracking-wide text-primary uppercase"
                            >
                                {{ copy.requirements.eyebrow }}
                            </p>
                            <h2
                                class="mt-2 text-3xl font-bold tracking-tight sm:text-4xl"
                            >
                                {{ copy.requirements.title }}
                            </h2>
                            <p
                                class="mt-3 text-base leading-7 text-muted-foreground"
                            >
                                {{ copy.requirements.subtitle }}
                            </p>
                        </div>
                        <ul class="grid gap-3 sm:grid-cols-1">
                            <li
                                v-for="(item, index) in copy.requirements.items"
                                :key="item"
                                class="flex items-center gap-3 rounded-xl border border-border bg-card px-4 py-4"
                            >
                                <span
                                    class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-accent text-accent-foreground"
                                >
                                    <component
                                        :is="requirementIcons[index]"
                                        class="size-5"
                                    />
                                </span>
                                <span
                                    class="text-sm font-medium text-foreground"
                                    >{{ item }}</span
                                >
                            </li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- Download call to action -->
            <section class="border-t border-border">
                <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
                    <div
                        class="flex flex-col items-center gap-6 rounded-3xl bg-primary px-6 py-12 text-center text-primary-foreground sm:px-12"
                    >
                        <h2
                            class="max-w-2xl text-3xl font-bold tracking-tight sm:text-4xl"
                        >
                            {{ copy.hero.title }}
                        </h2>
                        <p
                            class="max-w-xl text-sm text-primary-foreground/85 sm:text-base"
                        >
                            {{ copy.download.note }}
                        </p>
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
            </section>
        </main>

        <!-- Footer -->
        <footer id="contact" class="border-t border-border bg-card">
            <div class="mx-auto max-w-6xl px-4 py-12 sm:px-6">
                <div class="grid gap-10 md:grid-cols-[1.2fr_1fr]">
                    <div>
                        <div class="flex items-center gap-2.5">
                            <AppLogoIcon class="size-10 object-contain" />
                            <span class="text-base font-bold tracking-tight"
                                >Drclick</span
                            >
                        </div>
                        <p
                            class="mt-4 max-w-sm text-sm leading-6 text-muted-foreground"
                        >
                            {{ copy.footer.blurb }}
                        </p>
                    </div>

                    <div>
                        <h2
                            class="text-sm font-semibold tracking-wide text-foreground uppercase"
                        >
                            {{ copy.footer.contactTitle }}
                        </h2>
                        <ul
                            class="mt-4 space-y-3 text-sm text-muted-foreground"
                        >
                            <li class="flex items-center gap-3">
                                <Phone class="size-4 shrink-0 text-primary" />
                                <span dir="ltr">{{
                                    copy.footer.phoneValue
                                }}</span>
                            </li>
                            <li class="flex items-center gap-3">
                                <Mail class="size-4 shrink-0 text-primary" />
                                <span dir="ltr">{{
                                    copy.footer.emailValue
                                }}</span>
                            </li>
                            <li class="flex items-start gap-3">
                                <CalendarClock
                                    class="mt-0.5 size-4 shrink-0 text-primary"
                                />
                                <span>{{ copy.footer.hoursValue }}</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div
                    class="mt-10 border-t border-border pt-6 text-xs text-muted-foreground"
                >
                    &copy; {{ new Date().getFullYear() }}
                    {{ copy.footer.rights }}
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
