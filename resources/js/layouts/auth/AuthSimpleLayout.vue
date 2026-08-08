<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    CalendarCheck2,
    HeartPulse,
    ShieldCheck,
    Sparkles,
    Stethoscope,
} from '@lucide/vue';
import { computed } from 'vue';
import { isTauri } from '@tauri-apps/api/core';
import { onMounted, ref } from 'vue';
import { home, login } from '@/routes';

const page = usePage();
const productName = 'Drclick';
const authHome = ref(home());
const isWideOnboarding = computed(() =>
    ['auth/Register', 'auth/JoinCabinet', 'auth/DesktopCabinetLogin'].includes(
        page.component,
    ),
);

defineProps<{
    title?: string;
    description?: string;
}>();

onMounted(() => {
    if (isTauri()) {
        authHome.value = login();
    }
});
</script>

<template>
    <div
        class="relative min-h-svh overflow-hidden bg-[#f3f8fb] text-slate-950 lg:grid lg:grid-cols-[minmax(380px,0.82fr)_minmax(560px,1.18fr)] dark:bg-slate-950 dark:text-white"
    >
        <section
            class="relative hidden min-h-svh overflow-hidden bg-[linear-gradient(145deg,#082c46_0%,#0e527d_52%,#087ca2_100%)] px-12 py-10 text-white lg:flex lg:flex-col xl:px-16"
            aria-label="Présentation de Drclick"
        >
            <div
                class="absolute -top-32 -right-24 size-[28rem] rounded-full border-[56px] border-white/5"
                aria-hidden="true"
            />
            <div
                class="absolute -bottom-52 -left-36 size-[34rem] rounded-full bg-cyan-300/10 blur-3xl"
                aria-hidden="true"
            />
            <div
                class="absolute top-[28%] -right-12 h-72 w-72 rotate-12 rounded-[4rem] bg-white/[0.035]"
                aria-hidden="true"
            />

            <Link
                :href="authHome"
                class="relative z-10 inline-flex w-fit items-center gap-3 rounded-2xl focus-visible:ring-2 focus-visible:ring-white"
                aria-label="Retour à l’accueil Drclick"
            >
                <span
                    class="flex size-12 items-center justify-center rounded-2xl bg-white text-[#0d6797] shadow-xl shadow-slate-950/15"
                >
                    <Stethoscope class="size-7" aria-hidden="true" />
                </span>
                <span>
                    <span class="block text-xl font-extrabold tracking-tight">
                        {{ productName }}
                    </span>
                    <span
                        class="block text-[0.66rem] font-semibold tracking-[0.2em] text-cyan-100/70 uppercase"
                    >
                        Cabinet connecté
                    </span>
                </span>
            </Link>

            <div class="relative z-10 my-auto max-w-xl py-14">
                <div
                    class="mb-6 inline-flex items-center gap-2 rounded-full border border-cyan-100/20 bg-white/10 px-3 py-1.5 text-xs font-semibold text-cyan-50 backdrop-blur"
                >
                    <Sparkles
                        class="size-3.5 text-amber-300"
                        aria-hidden="true"
                    />
                    Pensé pour les professionnels de santé
                </div>

                <h1
                    class="max-w-lg text-4xl leading-[1.08] font-extrabold tracking-[-0.04em] xl:text-5xl"
                >
                    Votre cabinet, fluide du premier patient au dernier suivi.
                </h1>
                <p class="mt-6 max-w-lg text-base leading-7 text-cyan-50/75">
                    Un espace de travail clair pour les rendez-vous, les
                    dossiers patients et le pilotage quotidien de votre équipe.
                </p>

                <div class="mt-10 grid max-w-lg gap-3 sm:grid-cols-2">
                    <div
                        class="rounded-2xl border border-white/10 bg-white/[0.08] p-4 backdrop-blur-sm"
                    >
                        <CalendarCheck2
                            class="size-5 text-amber-300"
                            aria-hidden="true"
                        />
                        <p class="mt-3 text-sm font-bold">Agenda maîtrisé</p>
                        <p class="mt-1 text-xs leading-5 text-cyan-50/60">
                            Une vision immédiate de votre journée.
                        </p>
                    </div>
                    <div
                        class="rounded-2xl border border-white/10 bg-white/[0.08] p-4 backdrop-blur-sm"
                    >
                        <ShieldCheck
                            class="size-5 text-amber-300"
                            aria-hidden="true"
                        />
                        <p class="mt-3 text-sm font-bold">Accès contrôlé</p>
                        <p class="mt-1 text-xs leading-5 text-cyan-50/60">
                            Chaque membre dispose du bon niveau d’accès.
                        </p>
                    </div>
                </div>
            </div>

            <div
                class="relative z-10 flex items-center justify-between border-t border-white/10 pt-5 text-xs text-cyan-50/55"
            >
                <span>© {{ new Date().getFullYear() }} {{ productName }}</span>
                <span class="inline-flex items-center gap-1.5">
                    <HeartPulse class="size-3.5" aria-hidden="true" />
                    Conçu en Algérie
                </span>
            </div>
        </section>

        <main
            class="relative flex min-h-svh justify-center overflow-y-auto px-4 py-6 sm:px-8 sm:py-10 lg:px-10 xl:px-14"
            :class="isWideOnboarding ? 'items-start' : 'items-center'"
        >
            <div
                class="pointer-events-none absolute inset-0 opacity-70 dark:opacity-20"
                aria-hidden="true"
                style="
                    background-image:
                        radial-gradient(
                            circle at 78% 8%,
                            rgba(14, 165, 233, 0.12),
                            transparent 24rem
                        ),
                        radial-gradient(
                            circle at 18% 86%,
                            rgba(245, 158, 11, 0.08),
                            transparent 22rem
                        );
                "
            />

            <div
                class="relative w-full"
                :class="isWideOnboarding ? 'max-w-3xl' : 'max-w-lg'"
            >
                <Link
                    :href="authHome"
                    class="mb-7 flex w-fit items-center gap-3 rounded-xl lg:hidden"
                    aria-label="Retour à l’accueil Drclick"
                >
                    <span
                        class="flex size-10 items-center justify-center rounded-xl bg-[#0d6797] text-white shadow-lg shadow-sky-900/15"
                    >
                        <Stethoscope class="size-5" aria-hidden="true" />
                    </span>
                    <span
                        class="font-extrabold tracking-tight text-slate-900 dark:text-white"
                    >
                        {{ productName }}
                    </span>
                </Link>

                <div
                    class="relative overflow-hidden rounded-[1.75rem] border border-white/90 bg-white shadow-[0_24px_80px_rgba(30,72,98,0.14)] dark:border-slate-800 dark:bg-slate-900"
                >
                    <div
                        class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-[#0d6797] via-cyan-400 to-amber-400"
                        aria-hidden="true"
                    />

                    <div class="p-6 sm:p-9 lg:p-10">
                        <div class="mb-8 space-y-2.5">
                            <h2
                                class="text-2xl leading-tight font-extrabold tracking-[-0.025em] text-slate-950 sm:text-3xl dark:text-white"
                            >
                                {{ title }}
                            </h2>
                            <p
                                class="max-w-2xl text-sm leading-6 text-slate-500 dark:text-slate-400"
                            >
                                {{ description }}
                            </p>
                        </div>

                        <slot />
                    </div>
                </div>

                <p
                    class="mt-5 text-center text-xs font-medium text-slate-400 dark:text-slate-500"
                >
                    Connexion chiffrée · Données médicales protégées
                </p>
            </div>
        </main>
    </div>
</template>
