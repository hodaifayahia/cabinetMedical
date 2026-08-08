<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Building2,
    CalendarClock,
    CheckCircle2,
    Download,
    FileText,
    HeartPulse,
    LockKeyhole,
    MonitorCheck,
    ShieldCheck,
    Stethoscope,
    UsersRound,
    WalletCards,
    Zap,
} from '@lucide/vue';
import { isTauri } from '@tauri-apps/api/core';
import { computed, onMounted, ref } from 'vue';
import DesktopDownloadLeadDialog from '@/components/DesktopDownloadLeadDialog.vue';
import DesktopOnboarding from '@/components/DesktopOnboarding.vue';
import {
    hasCompletedDesktopOnboarding,
    markDesktopOnboardingComplete,
} from '@/lib/desktopOnboarding';
import { dashboard, login, register } from '@/routes';

defineProps<{
    canRegister: boolean;
}>();

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

function openDownload(): void {
    if (!desktopRuntime.value) {
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

    const requestedDownload =
        new URLSearchParams(window.location.search).get('download') === '1';

    if (requestedDownload) {
        downloadDialogOpen.value = true;
        requestAnimationFrame(() => {
            document
                .getElementById('telecharger')
                ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    }
});
</script>

<template>
    <Head title="DrClickDz — Le cabinet médical connecté">
        <meta
            head-key="description"
            name="description"
            content="DrClickDz centralise les patients, rendez-vous, consultations et paiements de votre cabinet médical dans un espace clair et connecté."
        />
    </Head>

    <div
        v-if="!runtimeResolved"
        class="min-h-screen bg-slate-950"
        aria-live="polite"
        aria-label="Préparation de DrClickDz"
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
        class="min-h-screen overflow-x-hidden bg-[#f5fafc] text-slate-950 selection:bg-sky-200 selection:text-sky-950"
    >
        <header
            class="sticky top-0 z-40 border-b border-slate-200/80 bg-white/90 backdrop-blur-xl"
        >
            <div
                class="mx-auto flex h-18 max-w-7xl items-center justify-between gap-5 px-5 sm:px-8 lg:px-10"
            >
                <a
                    href="#accueil"
                    class="flex items-center gap-3 rounded-xl focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:outline-none"
                    aria-label="DrClickDz, retour à l’accueil"
                >
                    <span
                        class="flex size-10 items-center justify-center rounded-xl bg-gradient-to-br from-[#0877ad] to-[#09a1b7] text-white shadow-md shadow-sky-950/15"
                    >
                        <Stethoscope class="size-5" aria-hidden="true" />
                    </span>
                    <span>
                        <span class="block text-lg leading-5 font-black">
                            DrClickDz
                        </span>
                        <span
                            class="block text-[0.62rem] font-bold tracking-[0.2em] text-[#0877ad] uppercase"
                        >
                            Cabinet connecté
                        </span>
                    </span>
                </a>

                <nav
                    class="hidden items-center gap-7 text-sm font-semibold text-slate-600 lg:flex"
                    aria-label="Navigation principale"
                >
                    <a class="transition hover:text-[#0877ad]" href="#solution">
                        La solution
                    </a>
                    <a
                        class="transition hover:text-[#0877ad]"
                        href="#fonctionnement"
                    >
                        Comment ça marche
                    </a>
                    <a
                        class="transition hover:text-[#0877ad]"
                        href="#telecharger"
                    >
                        Application desktop
                    </a>
                </nav>

                <div class="flex items-center gap-2 sm:gap-3">
                    <template v-if="$page.props.auth.user">
                        <a
                            v-if="$page.props.auth.user.can.accessAdminPanel"
                            href="/admin"
                            class="inline-flex h-10 items-center gap-2 rounded-xl bg-slate-950 px-4 text-sm font-bold text-white transition hover:bg-slate-800 focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:outline-none"
                        >
                            Ouvrir la plateforme
                            <ArrowRight class="size-4" aria-hidden="true" />
                        </a>
                        <Link
                            v-else
                            :href="dashboard()"
                            class="inline-flex h-10 items-center gap-2 rounded-xl bg-slate-950 px-4 text-sm font-bold text-white transition hover:bg-slate-800 focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:outline-none"
                        >
                            Ouvrir le cabinet
                            <ArrowRight class="size-4" aria-hidden="true" />
                        </Link>
                    </template>
                    <template v-else>
                        <Link
                            :href="login()"
                            class="hidden h-10 items-center px-3 text-sm font-bold text-slate-700 transition hover:text-[#0877ad] sm:inline-flex"
                        >
                            Se connecter
                        </Link>
                        <Link
                            v-if="canRegister"
                            :href="register()"
                            class="inline-flex h-10 items-center gap-2 rounded-xl bg-slate-950 px-3 text-xs font-bold text-white transition hover:bg-slate-800 focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:outline-none sm:px-4 sm:text-sm"
                        >
                            Créer un cabinet
                            <ArrowRight
                                class="hidden size-4 sm:block"
                                aria-hidden="true"
                            />
                        </Link>
                    </template>
                </div>
            </div>
        </header>

        <main>
            <section
                id="accueil"
                class="relative isolate overflow-hidden bg-[#073e62] text-white"
            >
                <div
                    class="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(circle_at_14%_15%,rgba(14,165,233,0.3),transparent_34%),radial-gradient(circle_at_86%_72%,rgba(16,185,129,0.2),transparent_35%)]"
                />
                <div
                    class="pointer-events-none absolute -top-40 right-[8%] -z-10 size-[34rem] rounded-full border-[5rem] border-white/[0.035]"
                />

                <div
                    class="mx-auto grid min-h-[calc(100vh-4.5rem)] max-w-7xl items-center gap-12 px-5 py-16 sm:px-8 lg:grid-cols-[1.03fr_0.97fr] lg:px-10 lg:py-20"
                >
                    <div>
                        <div
                            class="inline-flex items-center gap-2 rounded-full border border-sky-300/25 bg-sky-200/10 px-4 py-2 text-xs font-bold text-sky-50"
                        >
                            <Zap
                                class="size-4 text-amber-300"
                                aria-hidden="true"
                            />
                            Pensé pour les professionnels de santé en Algérie
                        </div>

                        <h1
                            class="mt-7 max-w-3xl text-4xl leading-[1.06] font-black tracking-[-0.045em] sm:text-6xl lg:text-[4.45rem]"
                        >
                            Votre cabinet, fluide du premier patient au dernier
                            suivi.
                        </h1>
                        <p
                            class="mt-6 max-w-2xl text-base leading-8 text-sky-50/78 sm:text-lg"
                        >
                            DrClickDz réunit agenda, dossiers patients,
                            consultations, ordonnances et paiements dans un seul
                            espace de travail connecté, pour toute votre équipe.
                        </p>

                        <div class="mt-9 flex flex-col gap-3 sm:flex-row">
                            <button
                                type="button"
                                class="inline-flex h-13 items-center justify-center gap-2 rounded-xl bg-white px-6 font-extrabold text-[#075d8c] shadow-xl shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-sky-50 focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-[#073e62] focus-visible:outline-none"
                                @click="openDownload"
                            >
                                <Download class="size-5" aria-hidden="true" />
                                Télécharger pour Windows
                            </button>
                            <Link
                                v-if="!$page.props.auth.user && canRegister"
                                :href="register()"
                                class="inline-flex h-13 items-center justify-center gap-2 rounded-xl border border-white/25 bg-white/10 px-6 font-bold text-white transition hover:bg-white/15 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                            >
                                Créer mon cabinet
                                <ArrowRight class="size-4" aria-hidden="true" />
                            </Link>
                            <Link
                                v-else-if="!$page.props.auth.user"
                                :href="login()"
                                class="inline-flex h-13 items-center justify-center gap-2 rounded-xl border border-white/25 bg-white/10 px-6 font-bold text-white transition hover:bg-white/15 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                            >
                                Accéder à mon cabinet
                                <ArrowRight class="size-4" aria-hidden="true" />
                            </Link>
                        </div>

                        <div
                            class="mt-8 flex flex-wrap gap-x-6 gap-y-3 text-sm font-semibold text-sky-50/80"
                        >
                            <span class="inline-flex items-center gap-2">
                                <CheckCircle2
                                    class="size-4 text-emerald-300"
                                    aria-hidden="true"
                                />
                                Jusqu’à 3 utilisateurs
                            </span>
                            <span class="inline-flex items-center gap-2">
                                <CheckCircle2
                                    class="size-4 text-emerald-300"
                                    aria-hidden="true"
                                />
                                Essai 7 jours ou licence à vie
                            </span>
                            <span class="inline-flex items-center gap-2">
                                <CheckCircle2
                                    class="size-4 text-emerald-300"
                                    aria-hidden="true"
                                />
                                Accès web et desktop
                            </span>
                        </div>
                    </div>

                    <div class="relative mx-auto w-full max-w-2xl lg:mx-0">
                        <div
                            class="absolute -inset-5 -z-10 rounded-[2.5rem] bg-gradient-to-tr from-cyan-300/20 to-emerald-300/10 blur-2xl"
                        />
                        <div
                            class="overflow-hidden rounded-[1.7rem] border border-white/20 bg-white text-slate-950 shadow-2xl shadow-slate-950/35"
                        >
                            <div
                                class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-5 py-4"
                            >
                                <div class="flex items-center gap-3">
                                    <span
                                        class="flex size-9 items-center justify-center rounded-xl bg-[#0877ad] text-white"
                                    >
                                        <Stethoscope
                                            class="size-4.5"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <div>
                                        <p class="text-sm font-black">
                                            Cabinet El Amal
                                        </p>
                                        <p
                                            class="text-[0.68rem] text-slate-500"
                                        >
                                            Vue d’ensemble
                                        </p>
                                    </div>
                                </div>
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1.5 text-[0.68rem] font-bold text-emerald-700"
                                >
                                    <span
                                        class="size-1.5 rounded-full bg-emerald-500"
                                    />
                                    En ligne
                                </span>
                            </div>

                            <div class="space-y-5 p-5 sm:p-7">
                                <div
                                    class="rounded-2xl bg-gradient-to-br from-[#1555c8] to-[#079db8] p-6 text-white"
                                >
                                    <p class="text-xs font-bold text-sky-100">
                                        BONJOUR DR BENALI
                                    </p>
                                    <h2 class="mt-2 text-2xl font-black">
                                        Une journée bien organisée.
                                    </h2>
                                    <p class="mt-2 text-sm text-sky-50/80">
                                        8 rendez-vous sont prévus aujourd’hui.
                                    </p>
                                    <span
                                        class="mt-5 inline-flex rounded-lg bg-white px-4 py-2 text-xs font-extrabold text-blue-700"
                                    >
                                        Voir l’agenda
                                    </span>
                                </div>

                                <div
                                    class="grid grid-cols-2 gap-3 sm:grid-cols-4"
                                >
                                    <div
                                        class="rounded-xl border border-slate-200 p-3"
                                    >
                                        <CalendarClock
                                            class="size-4 text-blue-600"
                                            aria-hidden="true"
                                        />
                                        <p class="mt-3 text-xl font-black">8</p>
                                        <p
                                            class="text-[0.65rem] text-slate-500"
                                        >
                                            Rendez-vous
                                        </p>
                                    </div>
                                    <div
                                        class="rounded-xl border border-slate-200 p-3"
                                    >
                                        <HeartPulse
                                            class="size-4 text-violet-600"
                                            aria-hidden="true"
                                        />
                                        <p class="mt-3 text-xl font-black">5</p>
                                        <p
                                            class="text-[0.65rem] text-slate-500"
                                        >
                                            Consultations
                                        </p>
                                    </div>
                                    <div
                                        class="rounded-xl border border-slate-200 p-3"
                                    >
                                        <UsersRound
                                            class="size-4 text-amber-600"
                                            aria-hidden="true"
                                        />
                                        <p class="mt-3 text-xl font-black">
                                            246
                                        </p>
                                        <p
                                            class="text-[0.65rem] text-slate-500"
                                        >
                                            Patients
                                        </p>
                                    </div>
                                    <div
                                        class="rounded-xl border border-slate-200 p-3"
                                    >
                                        <WalletCards
                                            class="size-4 text-emerald-600"
                                            aria-hidden="true"
                                        />
                                        <p class="mt-3 text-xl font-black">6</p>
                                        <p
                                            class="text-[0.65rem] text-slate-500"
                                        >
                                            Paiements
                                        </p>
                                    </div>
                                </div>

                                <div
                                    class="flex items-center justify-between rounded-xl bg-slate-50 px-4 py-3"
                                >
                                    <div class="flex items-center gap-3">
                                        <span
                                            class="flex size-9 items-center justify-center rounded-full bg-sky-100 text-xs font-black text-sky-700"
                                        >
                                            YM
                                        </span>
                                        <div>
                                            <p class="text-xs font-bold">
                                                Prochain rendez-vous
                                            </p>
                                            <p
                                                class="text-[0.68rem] text-slate-500"
                                            >
                                                Yacine Mansouri · 10:30
                                            </p>
                                        </div>
                                    </div>
                                    <ArrowRight
                                        class="size-4 text-slate-400"
                                        aria-hidden="true"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section id="solution" class="py-20 sm:py-28">
                <div class="mx-auto max-w-7xl px-5 sm:px-8 lg:px-10">
                    <div class="mx-auto max-w-3xl text-center">
                        <p
                            class="text-xs font-black tracking-[0.2em] text-[#0877ad] uppercase"
                        >
                            Un quotidien plus simple
                        </p>
                        <h2
                            class="mt-4 text-3xl font-black tracking-tight sm:text-5xl"
                        >
                            Tout le cabinet avance dans le même espace.
                        </h2>
                        <p
                            class="mt-5 text-base leading-7 text-slate-600 sm:text-lg"
                        >
                            Retrouvez l’information utile au bon moment, sans
                            multiplier les fichiers ni les outils.
                        </p>
                    </div>

                    <div class="mt-14 grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                        <article
                            class="group rounded-3xl border border-slate-200 bg-white p-7 shadow-sm transition hover:-translate-y-1 hover:shadow-xl hover:shadow-sky-950/8"
                        >
                            <span
                                class="flex size-12 items-center justify-center rounded-2xl bg-sky-50 text-[#0877ad]"
                            >
                                <UsersRound class="size-6" aria-hidden="true" />
                            </span>
                            <h3 class="mt-6 text-xl font-black">
                                Dossiers patients
                            </h3>
                            <p class="mt-3 leading-7 text-slate-600">
                                Coordonnées, antécédents, documents et
                                historique clinique restent organisés autour de
                                chaque patient.
                            </p>
                        </article>

                        <article
                            class="group rounded-3xl border border-slate-200 bg-white p-7 shadow-sm transition hover:-translate-y-1 hover:shadow-xl hover:shadow-sky-950/8"
                        >
                            <span
                                class="flex size-12 items-center justify-center rounded-2xl bg-blue-50 text-blue-700"
                            >
                                <CalendarClock
                                    class="size-6"
                                    aria-hidden="true"
                                />
                            </span>
                            <h3 class="mt-6 text-xl font-black">
                                Agenda du cabinet
                            </h3>
                            <p class="mt-3 leading-7 text-slate-600">
                                Visualisez la journée, les disponibilités et les
                                rendez-vous de l’équipe depuis un planning
                                clair.
                            </p>
                        </article>

                        <article
                            class="group rounded-3xl border border-slate-200 bg-white p-7 shadow-sm transition hover:-translate-y-1 hover:shadow-xl hover:shadow-sky-950/8"
                        >
                            <span
                                class="flex size-12 items-center justify-center rounded-2xl bg-violet-50 text-violet-700"
                            >
                                <HeartPulse class="size-6" aria-hidden="true" />
                            </span>
                            <h3 class="mt-6 text-xl font-black">
                                Consultation structurée
                            </h3>
                            <p class="mt-3 leading-7 text-slate-600">
                                Notes, mesures, actes et suivi sont réunis dans
                                un parcours adapté à la pratique médicale.
                            </p>
                        </article>

                        <article
                            class="group rounded-3xl border border-slate-200 bg-white p-7 shadow-sm transition hover:-translate-y-1 hover:shadow-xl hover:shadow-sky-950/8"
                        >
                            <span
                                class="flex size-12 items-center justify-center rounded-2xl bg-amber-50 text-amber-700"
                            >
                                <FileText class="size-6" aria-hidden="true" />
                            </span>
                            <h3 class="mt-6 text-xl font-black">
                                Ordonnances et documents
                            </h3>
                            <p class="mt-3 leading-7 text-slate-600">
                                Préparez les prescriptions et documents
                                cliniques, puis conservez-les dans le bon
                                dossier.
                            </p>
                        </article>

                        <article
                            class="group rounded-3xl border border-slate-200 bg-white p-7 shadow-sm transition hover:-translate-y-1 hover:shadow-xl hover:shadow-sky-950/8"
                        >
                            <span
                                class="flex size-12 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-700"
                            >
                                <WalletCards
                                    class="size-6"
                                    aria-hidden="true"
                                />
                            </span>
                            <h3 class="mt-6 text-xl font-black">
                                Paiements lisibles
                            </h3>
                            <p class="mt-3 leading-7 text-slate-600">
                                Suivez les règlements et les recettes avec une
                                vue simple de l’activité quotidienne du cabinet.
                            </p>
                        </article>

                        <article
                            class="group rounded-3xl border border-slate-200 bg-white p-7 shadow-sm transition hover:-translate-y-1 hover:shadow-xl hover:shadow-sky-950/8"
                        >
                            <span
                                class="flex size-12 items-center justify-center rounded-2xl bg-cyan-50 text-cyan-700"
                            >
                                <ShieldCheck
                                    class="size-6"
                                    aria-hidden="true"
                                />
                            </span>
                            <h3 class="mt-6 text-xl font-black">
                                Équipe et accès contrôlés
                            </h3>
                            <p class="mt-3 leading-7 text-slate-600">
                                Le propriétaire approuve chaque membre et lui
                                attribue un rôle adapté, dans la limite de trois
                                comptes.
                            </p>
                        </article>
                    </div>
                </div>
            </section>

            <section
                id="fonctionnement"
                class="border-y border-slate-200 bg-white py-20 sm:py-28"
            >
                <div
                    class="mx-auto grid max-w-7xl gap-14 px-5 sm:px-8 lg:grid-cols-[0.8fr_1.2fr] lg:items-center lg:px-10"
                >
                    <div>
                        <p
                            class="text-xs font-black tracking-[0.2em] text-[#0877ad] uppercase"
                        >
                            Mise en route guidée
                        </p>
                        <h2
                            class="mt-4 text-3xl font-black tracking-tight sm:text-5xl"
                        >
                            De l’inscription à votre premier rendez-vous.
                        </h2>
                        <p class="mt-5 text-lg leading-8 text-slate-600">
                            Un parcours clair protège l’accès au cabinet tout en
                            laissant l’administrateur de la plateforme choisir
                            la licence adaptée.
                        </p>
                    </div>

                    <ol class="grid gap-4 sm:grid-cols-3">
                        <li
                            class="relative rounded-3xl border border-slate-200 bg-[#f8fbfc] p-6"
                        >
                            <span
                                class="text-5xl font-black text-sky-100"
                                aria-hidden="true"
                            >
                                01
                            </span>
                            <Building2
                                class="mt-5 size-6 text-[#0877ad]"
                                aria-hidden="true"
                            />
                            <h3 class="mt-4 font-black">Créez le cabinet</h3>
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                Renseignez les informations du médecin et de la
                                structure.
                            </p>
                        </li>
                        <li
                            class="relative rounded-3xl border border-sky-200 bg-sky-50/60 p-6"
                        >
                            <span
                                class="text-5xl font-black text-sky-200"
                                aria-hidden="true"
                            >
                                02
                            </span>
                            <LockKeyhole
                                class="mt-5 size-6 text-[#0877ad]"
                                aria-hidden="true"
                            />
                            <h3 class="mt-4 font-black">Activez la licence</h3>
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                Saisissez le code d’essai de 7 jours ou de
                                licence à vie remis par DrClickDz.
                            </p>
                        </li>
                        <li
                            class="relative rounded-3xl border border-emerald-200 bg-emerald-50/60 p-6"
                        >
                            <span
                                class="text-5xl font-black text-emerald-200"
                                aria-hidden="true"
                            >
                                03
                            </span>
                            <MonitorCheck
                                class="mt-5 size-6 text-emerald-700"
                                aria-hidden="true"
                            />
                            <h3 class="mt-4 font-black">
                                Travaillez en équipe
                            </h3>
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                Connectez le médecin et ses collaborateurs au
                                même cabinet.
                            </p>
                        </li>
                    </ol>
                </div>
            </section>

            <section id="telecharger" class="px-5 py-20 sm:px-8 sm:py-28">
                <div
                    class="relative mx-auto grid max-w-7xl overflow-hidden rounded-[2rem] bg-[#073e62] text-white shadow-2xl shadow-sky-950/20 lg:grid-cols-[1.08fr_0.92fr]"
                >
                    <div
                        class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_90%_10%,rgba(34,211,238,0.22),transparent_32%)]"
                    />
                    <div class="relative p-8 sm:p-12 lg:p-16">
                        <span
                            class="inline-flex items-center gap-2 rounded-full bg-white/10 px-4 py-2 text-xs font-bold text-sky-100 ring-1 ring-white/15"
                        >
                            <MonitorCheck class="size-4" aria-hidden="true" />
                            Application Windows DrClickDz
                        </span>
                        <h2
                            class="mt-6 max-w-2xl text-3xl font-black tracking-tight sm:text-5xl"
                        >
                            Votre cabinet à portée de clic, sur votre
                            ordinateur.
                        </h2>
                        <p
                            class="mt-5 max-w-xl text-lg leading-8 text-sky-50/75"
                        >
                            Installez le client desktop léger, puis
                            connectez-vous à votre cabinet hébergé. Vos données
                            restent synchronisées avec l’espace web.
                        </p>

                        <button
                            type="button"
                            class="mt-8 inline-flex h-13 items-center gap-2 rounded-xl bg-white px-6 font-extrabold text-[#075d8c] shadow-xl shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-sky-50 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                            data-testid="open-desktop-download-form"
                            @click="openDownload"
                        >
                            <Download class="size-5" aria-hidden="true" />
                            {{
                                desktopDownload?.label ??
                                'Télécharger pour Windows'
                            }}
                        </button>
                        <p
                            v-if="!desktopDownload?.available"
                            class="mt-3 max-w-lg text-sm text-amber-200"
                            role="status"
                        >
                            {{
                                desktopDownload?.reason ??
                                'Le programme d’installation sera disponible prochainement.'
                            }}
                        </p>
                    </div>

                    <div
                        class="relative flex items-center border-t border-white/10 bg-white/[0.055] p-8 sm:p-12 lg:border-t-0 lg:border-l"
                    >
                        <div class="w-full space-y-4">
                            <div
                                class="flex gap-4 rounded-2xl border border-white/10 bg-white/[0.06] p-5"
                            >
                                <span
                                    class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-emerald-300/15 text-emerald-300"
                                >
                                    <CheckCircle2
                                        class="size-5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div>
                                    <h3 class="font-bold">
                                        Installation simple
                                    </h3>
                                    <p
                                        class="mt-1 text-sm leading-6 text-sky-50/65"
                                    >
                                        Téléchargez l’installeur Windows et
                                        suivez les étapes à l’écran.
                                    </p>
                                </div>
                            </div>
                            <div
                                class="flex gap-4 rounded-2xl border border-white/10 bg-white/[0.06] p-5"
                            >
                                <span
                                    class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-sky-300/15 text-sky-300"
                                >
                                    <Building2
                                        class="size-5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div>
                                    <h3 class="font-bold">
                                        Nouveau ou déjà membre
                                    </h3>
                                    <p
                                        class="mt-1 text-sm leading-6 text-sky-50/65"
                                    >
                                        Créez votre cabinet une seule fois, ou
                                        rejoignez celui de votre responsable.
                                    </p>
                                </div>
                            </div>
                            <div
                                class="flex gap-4 rounded-2xl border border-white/10 bg-white/[0.06] p-5"
                            >
                                <span
                                    class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-amber-300/15 text-amber-300"
                                >
                                    <ShieldCheck
                                        class="size-5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div>
                                    <h3 class="font-bold">Accès protégé</h3>
                                    <p
                                        class="mt-1 text-sm leading-6 text-sky-50/65"
                                    >
                                        L’activation et les rôles définissent ce
                                        que chaque membre peut utiliser.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-slate-200 bg-white">
            <div
                class="mx-auto flex max-w-7xl flex-col gap-5 px-5 py-8 sm:px-8 md:flex-row md:items-center md:justify-between lg:px-10"
            >
                <div class="flex items-center gap-3">
                    <span
                        class="flex size-9 items-center justify-center rounded-xl bg-[#0877ad] text-white"
                    >
                        <Stethoscope class="size-4" aria-hidden="true" />
                    </span>
                    <div>
                        <p class="font-black">DrClickDz</p>
                        <p class="text-xs text-slate-500">
                            Le cabinet médical connecté.
                        </p>
                    </div>
                </div>
                <div
                    class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-600"
                >
                    <Link
                        v-if="!$page.props.auth.user"
                        :href="login()"
                        class="font-semibold hover:text-[#0877ad]"
                    >
                        Se connecter
                    </Link>
                    <a href="/join" class="font-semibold hover:text-[#0877ad]">
                        Rejoindre un cabinet
                    </a>
                    <button
                        type="button"
                        class="font-semibold hover:text-[#0877ad]"
                        @click="openDownload"
                    >
                        Télécharger l’application
                    </button>
                </div>
            </div>
        </footer>

        <DesktopDownloadLeadDialog
            v-if="!desktopRuntime"
            v-model:open="downloadDialogOpen"
            :available="desktopDownload?.available ?? false"
            :action="desktopDownload?.url ?? '/desktop/download'"
            :label="desktopDownload?.label ?? 'Télécharger pour Windows'"
            :reason="desktopDownload?.reason ?? null"
        />
    </div>
</template>
