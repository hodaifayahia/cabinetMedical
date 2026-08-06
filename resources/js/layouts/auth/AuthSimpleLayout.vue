<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { CalendarDays, ShieldCheck, Stethoscope } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

const page = usePage();
const clinicName = String(page.props.name ?? 'ClickDZ');

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="min-h-svh bg-[#edf3f6] lg:grid lg:grid-cols-[minmax(0,1.08fr)_minmax(420px,0.92fr)]"
    >
        <section
            class="relative hidden overflow-hidden bg-[#173f5d] px-12 py-10 text-white lg:flex lg:flex-col xl:px-20"
        >
            <div
                class="absolute -top-32 -right-28 size-96 rounded-full bg-[#e3a719]/15 blur-2xl"
            />
            <div
                class="absolute -bottom-40 -left-28 size-[28rem] rounded-full border-[32px] border-white/5"
            />

            <Link :href="home()" class="relative z-10 flex items-center gap-3">
                <div
                    class="flex size-11 items-center justify-center rounded-xl bg-[#e3a719] text-white shadow-lg shadow-black/10"
                >
                    <Stethoscope class="size-6" />
                </div>
                <span class="text-lg font-bold tracking-tight">{{
                    clinicName
                }}</span>
            </Link>

            <div class="relative z-10 my-auto max-w-xl py-14">
                <p
                    class="mb-4 text-sm font-semibold tracking-[0.22em] text-[#f4c54d] uppercase"
                >
                    Gestion médicale simplifiée
                </p>
                <h1
                    class="text-4xl leading-tight font-bold tracking-tight xl:text-5xl"
                >
                    Une organisation claire pour des soins plus sereins.
                </h1>
                <p class="mt-6 max-w-lg text-base leading-7 text-blue-100/80">
                    Retrouvez vos patients, vos rendez-vous et votre activité
                    dans un espace sécurisé, pensé pour le quotidien du cabinet.
                </p>

                <div class="mt-10 grid max-w-lg gap-3 sm:grid-cols-2">
                    <div
                        class="rounded-2xl border border-white/10 bg-white/10 p-4 backdrop-blur-sm"
                    >
                        <CalendarDays class="size-5 text-[#f4c54d]" />
                        <p class="mt-3 text-sm font-semibold">
                            Rendez-vous maîtrisés
                        </p>
                        <p class="mt-1 text-xs leading-5 text-blue-100/65">
                            Un agenda lisible et toujours à jour.
                        </p>
                    </div>
                    <div
                        class="rounded-2xl border border-white/10 bg-white/10 p-4 backdrop-blur-sm"
                    >
                        <ShieldCheck class="size-5 text-[#f4c54d]" />
                        <p class="mt-3 text-sm font-semibold">
                            Données protégées
                        </p>
                        <p class="mt-1 text-xs leading-5 text-blue-100/65">
                            Un accès contrôlé pour votre équipe.
                        </p>
                    </div>
                </div>
            </div>

            <p class="relative z-10 text-xs text-blue-100/50">
                © {{ new Date().getFullYear() }} {{ clinicName }}
            </p>
        </section>

        <main
            class="flex min-h-svh items-center justify-center px-5 py-8 sm:px-8 lg:px-12"
        >
            <div class="w-full max-w-md">
                <Link
                    :href="home()"
                    class="mb-8 flex items-center justify-center gap-3 lg:hidden"
                >
                    <div
                        class="flex size-10 items-center justify-center rounded-xl bg-[#e3a719] text-white shadow-sm"
                    >
                        <AppLogoIcon class="size-5 fill-current" />
                    </div>
                    <span class="font-bold tracking-tight text-slate-800">{{
                        clinicName
                    }}</span>
                </Link>

                <div
                    class="rounded-3xl border border-white/80 bg-white p-7 shadow-[0_18px_60px_rgba(28,60,79,0.12)] sm:p-10"
                >
                    <div class="mb-8 space-y-2">
                        <h2
                            class="text-2xl font-bold tracking-tight text-slate-900"
                        >
                            {{ title }}
                        </h2>
                        <p class="text-sm leading-6 text-slate-500">
                            {{ description }}
                        </p>
                    </div>
                    <slot />
                </div>

                <p class="mt-6 text-center text-xs text-slate-400">
                    Accès sécurisé · {{ clinicName }}
                </p>
            </div>
        </main>
    </div>
</template>
