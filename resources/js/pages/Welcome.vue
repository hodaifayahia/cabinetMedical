<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
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
import { computed, ref, type Component } from 'vue';
import AppMockup from '@/components/landing/AppMockup.vue';
import DownloadButton from '@/components/landing/DownloadButton.vue';
import LanguageSwitcher from '@/components/landing/LanguageSwitcher.vue';
import { useLandingLocale } from '@/components/landing/translations';

// `canRegister` is still provided by the home route and asserted by the
// feature test, but the public landing page intentionally exposes no
// sign-in / sign-up / join links — its only conversion goal is the download.
defineProps<{
    canRegister: boolean;
}>();

const page = usePage();
const desktopDownload = computed(() => page.props.desktopDownload);

const { locale, dir, copy, setLocale } = useLandingLocale();

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
    { href: '#features', label: copy.value.nav.features },
    { href: '#how', label: copy.value.nav.how },
    { href: '#roles', label: copy.value.nav.roles },
    { href: '#requirements', label: copy.value.nav.requirements },
    { href: '#contact', label: copy.value.nav.contact },
]);

function closeMobileNav(): void {
    mobileNavOpen.value = false;
}
</script>

<template>
    <Head :title="copy.tagline" />

    <div
        :dir="dir"
        :lang="locale"
        :style="
            locale === 'ar'
                ? { fontFamily: `'Noto Naskh Arabic', 'Cairo', 'Segoe UI', 'Tahoma', 'Geeza Pro', 'Arial', sans-serif` }
                : undefined
        "
        class="min-h-screen bg-background text-foreground"
        :class="locale === 'ar' ? 'leading-relaxed' : ''"
    >
        <!-- Sticky header -->
        <header
            class="sticky top-0 z-40 border-b border-border/70 bg-background/85 backdrop-blur"
        >
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
                <a href="#top" class="flex items-center gap-2.5">
                    <span
                        class="flex size-9 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-sm"
                    >
                        <Stethoscope class="size-5" />
                    </span>
                    <span class="flex flex-col leading-tight">
                        <span class="text-base font-bold tracking-tight">MediSmart</span>
                        <span class="hidden text-[11px] text-muted-foreground sm:block">
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
                    />
                </div>
            </div>
        </header>

        <main id="top">
            <!-- Hero -->
            <section class="relative overflow-hidden">
                <div
                    class="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[420px] bg-[radial-gradient(60%_120%_at_50%_0%,hsl(202_85%_95%),transparent)]"
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
                        <p class="mt-5 max-w-xl text-base leading-7 text-muted-foreground sm:text-lg">
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

                        <div class="mt-8 flex flex-col items-start gap-3 sm:flex-row sm:items-center">
                            <DownloadButton
                                :available="desktopDownload?.available ?? false"
                                :url="desktopDownload?.url ?? null"
                                :reason="desktopDownload?.reason ?? null"
                                :label="copy.download.cta"
                                :unavailable-label="copy.download.unavailable"
                                size="lg"
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
            <section id="features" class="border-t border-border bg-card">
                <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:py-20">
                    <div class="max-w-2xl">
                        <p class="text-sm font-semibold tracking-wide text-primary uppercase">
                            {{ copy.benefits.eyebrow }}
                        </p>
                        <h2 class="mt-2 text-3xl font-bold tracking-tight sm:text-4xl">
                            {{ copy.benefits.title }}
                        </h2>
                        <p class="mt-3 text-base leading-7 text-muted-foreground">
                            {{ copy.benefits.subtitle }}
                        </p>
                    </div>

                    <div class="mt-12 grid gap-px overflow-hidden rounded-2xl border border-border bg-border sm:grid-cols-2 lg:grid-cols-3">
                        <article
                            v-for="(benefit, index) in copy.benefits.items"
                            :key="benefit.title"
                            class="bg-card p-6 transition hover:bg-accent/30"
                        >
                            <span
                                class="flex size-11 items-center justify-center rounded-xl bg-primary/10 text-primary"
                            >
                                <component :is="benefitIcons[index]" class="size-5" />
                            </span>
                            <h3 class="mt-4 text-lg font-semibold text-foreground">
                                {{ benefit.title }}
                            </h3>
                            <p class="mt-2 text-sm leading-6 text-muted-foreground">
                                {{ benefit.body }}
                            </p>
                        </article>
                    </div>
                </div>
            </section>

            <!-- How it works -->
            <section id="how" class="border-t border-border">
                <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:py-20">
                    <div class="max-w-2xl">
                        <p class="text-sm font-semibold tracking-wide text-primary uppercase">
                            {{ copy.how.eyebrow }}
                        </p>
                        <h2 class="mt-2 text-3xl font-bold tracking-tight sm:text-4xl">
                            {{ copy.how.title }}
                        </h2>
                        <p class="mt-3 text-base leading-7 text-muted-foreground">
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
                            <h3 class="mt-4 text-lg font-semibold text-foreground">
                                {{ step.title }}
                            </h3>
                            <p class="mt-2 text-sm leading-6 text-muted-foreground">
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
                        <p class="text-sm font-semibold tracking-wide text-primary uppercase">
                            {{ copy.roles.eyebrow }}
                        </p>
                        <h2 class="mt-2 text-3xl font-bold tracking-tight sm:text-4xl">
                            {{ copy.roles.title }}
                        </h2>
                        <p class="mt-3 text-base leading-7 text-muted-foreground">
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
                                    <component :is="roleIcons[index]" class="size-5" />
                                </span>
                                <div>
                                    <h3 class="text-lg font-semibold text-foreground">
                                        {{ role.title }}
                                    </h3>
                                    <p class="text-sm text-muted-foreground">{{ role.body }}</p>
                                </div>
                            </div>
                            <ul class="mt-5 space-y-2.5 border-t border-border pt-5">
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

            <!-- System requirements -->
            <section id="requirements" class="border-t border-border">
                <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:py-20">
                    <div class="grid gap-10 lg:grid-cols-[0.9fr_1.1fr] lg:items-center">
                        <div class="max-w-md">
                            <p class="text-sm font-semibold tracking-wide text-primary uppercase">
                                {{ copy.requirements.eyebrow }}
                            </p>
                            <h2 class="mt-2 text-3xl font-bold tracking-tight sm:text-4xl">
                                {{ copy.requirements.title }}
                            </h2>
                            <p class="mt-3 text-base leading-7 text-muted-foreground">
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
                                    <component :is="requirementIcons[index]" class="size-5" />
                                </span>
                                <span class="text-sm font-medium text-foreground">{{ item }}</span>
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
                        <h2 class="max-w-2xl text-3xl font-bold tracking-tight sm:text-4xl">
                            {{ copy.hero.title }}
                        </h2>
                        <p class="max-w-xl text-sm text-primary-foreground/85 sm:text-base">
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
                            <span
                                class="flex size-9 items-center justify-center rounded-xl bg-primary text-primary-foreground"
                            >
                                <Stethoscope class="size-5" />
                            </span>
                            <span class="text-base font-bold tracking-tight">MediSmart</span>
                        </div>
                        <p class="mt-4 max-w-sm text-sm leading-6 text-muted-foreground">
                            {{ copy.footer.blurb }}
                        </p>
                    </div>

                    <div>
                        <h2 class="text-sm font-semibold tracking-wide text-foreground uppercase">
                            {{ copy.footer.contactTitle }}
                        </h2>
                        <ul class="mt-4 space-y-3 text-sm text-muted-foreground">
                            <li class="flex items-center gap-3">
                                <Phone class="size-4 shrink-0 text-primary" />
                                <span dir="ltr">{{ copy.footer.phoneValue }}</span>
                            </li>
                            <li class="flex items-center gap-3">
                                <Mail class="size-4 shrink-0 text-primary" />
                                <span dir="ltr">{{ copy.footer.emailValue }}</span>
                            </li>
                            <li class="flex items-start gap-3">
                                <CalendarClock class="mt-0.5 size-4 shrink-0 text-primary" />
                                <span>{{ copy.footer.hoursValue }}</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div
                    class="mt-10 border-t border-border pt-6 text-xs text-muted-foreground"
                >
                    &copy; {{ new Date().getFullYear() }} {{ copy.footer.rights }}
                </div>
            </div>
        </footer>
    </div>
</template>
