<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Building2,
    Check,
    CircleUserRound,
    LockKeyhole,
    LogIn,
    Mail,
    Phone,
    ShieldCheck,
    Stethoscope,
    UsersRound,
} from '@lucide/vue';
import { login, register } from '@/routes';

defineProps<{
    canRegister: boolean;
}>();

const profileDetails = [
    { label: 'Nom complet', icon: CircleUserRound },
    { label: 'Téléphone', icon: Phone },
    { label: 'Adresse e-mail', icon: Mail },
    { label: 'Spécialité', icon: Stethoscope },
    { label: 'Cabinet', icon: Building2 },
];
</script>

<template>
    <section
        role="dialog"
        aria-modal="true"
        aria-labelledby="desktop-onboarding-title"
        aria-describedby="desktop-onboarding-description"
        data-test="desktop-onboarding"
        class="fixed inset-0 z-20 grid min-h-screen place-items-center overflow-y-auto bg-slate-950/94 px-4 py-8 text-slate-950 backdrop-blur-xl sm:px-6"
    >
        <div
            class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_15%_12%,rgba(14,165,233,0.22),transparent_28%),radial-gradient(circle_at_90%_85%,rgba(16,185,129,0.2),transparent_30%)]"
        />

        <div
            class="relative w-full max-w-4xl overflow-hidden rounded-[2rem] border border-white/15 bg-white shadow-[0_35px_100px_rgba(2,8,23,0.55)]"
        >
            <div
                class="grid border-b border-slate-200 bg-slate-50/90 lg:grid-cols-[1fr_auto]"
            >
                <div class="p-7 sm:p-9">
                    <div
                        class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-bold tracking-wide text-emerald-700 uppercase"
                    >
                        <Check class="size-3.5" />
                        DrClickDz est installé
                    </div>
                    <h1
                        id="desktop-onboarding-title"
                        class="mt-5 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl"
                    >
                        Configurons votre accès au cabinet
                    </h1>
                    <p
                        id="desktop-onboarding-description"
                        class="mt-3 max-w-2xl text-sm leading-6 text-slate-600 sm:text-base"
                    >
                        Pour commencer, dites-nous si vous créez votre propre
                        cabinet ou si vous rejoignez une équipe existante.
                    </p>
                </div>
                <div
                    class="hidden items-center border-l border-slate-200 px-9 lg:flex"
                >
                    <div
                        class="flex size-20 items-center justify-center rounded-3xl bg-gradient-to-br from-blue-600 to-cyan-500 text-white shadow-lg shadow-blue-500/25"
                    >
                        <Stethoscope class="size-9" />
                    </div>
                </div>
            </div>

            <div class="p-7 sm:p-9">
                <p
                    class="text-xs font-black tracking-[0.16em] text-blue-600 uppercase"
                >
                    Étape 1 · Choisissez votre situation
                </p>

                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <Link
                        v-if="canRegister"
                        :href="register()"
                        data-test="desktop-create-cabinet"
                        class="group flex min-h-52 flex-col rounded-3xl border-2 border-blue-100 bg-gradient-to-br from-blue-50 to-white p-6 transition duration-200 hover:-translate-y-1 hover:border-blue-400 hover:shadow-xl hover:shadow-blue-950/10 focus-visible:ring-4 focus-visible:ring-blue-200 focus-visible:outline-none"
                    >
                        <span
                            class="flex size-12 items-center justify-center rounded-2xl bg-blue-600 text-white shadow-lg shadow-blue-600/20"
                        >
                            <Building2 class="size-6" />
                        </span>
                        <span class="mt-5 text-xl font-black text-slate-950">
                            Créer mon cabinet
                        </span>
                        <span class="mt-2 text-sm leading-6 text-slate-600">
                            Créez le cabinet, renseignez votre spécialité et
                            devenez son propriétaire.
                        </span>
                        <span
                            class="mt-auto flex items-center gap-2 pt-5 text-sm font-bold text-blue-700"
                        >
                            Commencer l’inscription
                            <ArrowRight
                                class="size-4 transition group-hover:translate-x-1"
                            />
                        </span>
                    </Link>

                    <div
                        v-else
                        class="flex min-h-52 flex-col rounded-3xl border-2 border-slate-200 bg-slate-50 p-6 text-slate-500"
                    >
                        <span
                            class="flex size-12 items-center justify-center rounded-2xl bg-slate-200"
                        >
                            <Building2 class="size-6" />
                        </span>
                        <span class="mt-5 text-xl font-black">
                            Création temporairement indisponible
                        </span>
                        <span class="mt-2 text-sm leading-6">
                            Vous pouvez rejoindre un cabinet existant ou vous
                            connecter à un compte déjà créé.
                        </span>
                    </div>

                    <Link
                        href="/desktop/cabinet-login"
                        data-test="desktop-existing-cabinet"
                        class="group flex min-h-52 flex-col rounded-3xl border-2 border-emerald-100 bg-gradient-to-br from-emerald-50 to-white p-6 transition duration-200 hover:-translate-y-1 hover:border-emerald-400 hover:shadow-xl hover:shadow-emerald-950/10 focus-visible:ring-4 focus-visible:ring-emerald-200 focus-visible:outline-none"
                    >
                        <span
                            class="flex size-12 items-center justify-center rounded-2xl bg-emerald-600 text-white shadow-lg shadow-emerald-600/20"
                        >
                            <UsersRound class="size-6" />
                        </span>
                        <span class="mt-5 text-xl font-black text-slate-950">
                            Mon cabinet existe déjà
                        </span>
                        <span class="mt-2 text-sm leading-6 text-slate-600">
                            Saisissez l’e-mail du propriétaire, puis les
                            identifiants que votre administrateur a créés pour
                            vous.
                        </span>
                        <span
                            class="mt-auto flex items-center gap-2 pt-5 text-sm font-bold text-emerald-700"
                        >
                            Connecter ce poste
                            <ArrowRight
                                class="size-4 transition group-hover:translate-x-1"
                            />
                        </span>
                    </Link>
                </div>

                <div
                    class="mt-7 rounded-3xl border border-slate-200 bg-slate-50 p-5 sm:p-6"
                >
                    <div
                        class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"
                    >
                        <div>
                            <p class="text-sm font-black text-slate-900">
                                Étape 2 · Complétez votre profil sécurisé
                            </p>
                            <p class="mt-1 text-xs leading-5 text-slate-500">
                                Pour une création : nom, téléphone, e-mail,
                                spécialité et nom du cabinet.
                            </p>
                            <p class="mt-1 text-xs leading-5 text-slate-500">
                                Pour un membre : l’e-mail du propriétaire
                                identifie le cabinet, puis vos identifiants
                                personnels ouvrent votre session.
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <span
                                v-for="detail in profileDetails"
                                :key="detail.label"
                                class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700"
                            >
                                <component
                                    :is="detail.icon"
                                    class="size-3.5 text-blue-600"
                                />
                                {{ detail.label }}
                            </span>
                        </div>
                    </div>
                </div>

                <div
                    class="mt-6 flex flex-col items-center justify-between gap-4 border-t border-slate-200 pt-6 sm:flex-row"
                >
                    <div class="flex items-center gap-2 text-xs text-slate-500">
                        <ShieldCheck class="size-4 text-emerald-600" />
                        Connexion sécurisée · Données rattachées à votre cabinet
                    </div>
                    <Link
                        :href="login()"
                        data-test="desktop-existing-account"
                        class="inline-flex h-11 items-center gap-2 rounded-xl px-4 text-sm font-bold text-slate-700 transition hover:bg-slate-100 hover:text-blue-700 focus-visible:ring-2 focus-visible:ring-blue-300 focus-visible:outline-none"
                    >
                        <LockKeyhole class="size-4" />
                        J’ai déjà un compte
                        <LogIn class="size-4" />
                    </Link>
                </div>
            </div>
        </div>
    </section>
</template>
