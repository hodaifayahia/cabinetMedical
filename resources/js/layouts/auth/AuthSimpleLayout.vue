<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { LockKeyhole } from '@lucide/vue';
import { isTauri } from '@tauri-apps/api/core';
import { computed, onMounted, ref } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { home, login } from '@/routes';

const page = usePage();
const authHome = ref(home());
const desktopRuntime = ref(false);
const isWideForm = computed(() =>
    ['auth/Register', 'auth/JoinCabinet', 'auth/DesktopCabinetLogin'].includes(
        page.component,
    ),
);

defineProps<{
    title?: string;
    description?: string;
}>();

onMounted(() => {
    desktopRuntime.value = isTauri();

    if (desktopRuntime.value) {
        authHome.value = login();
    }
});
</script>

<template>
    <main
        class="min-h-svh bg-muted/35 px-4 py-8 text-foreground sm:px-6 sm:py-12 dark:bg-background"
    >
        <div
            class="mx-auto flex w-full flex-col items-center"
            :class="isWideForm ? 'max-w-3xl' : 'max-w-md'"
        >
            <Link
                :href="authHome"
                class="mb-7 inline-flex items-center gap-3 rounded-xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                aria-label="Retour à l’accueil Drclick"
            >
                <AppLogoIcon class="size-11 object-contain" />
                <span class="text-xl font-bold tracking-tight">Drclick</span>
            </Link>

            <section
                v-if="desktopRuntime"
                class="mb-5 flex w-full items-center gap-4 rounded-2xl border border-brand/15 bg-brand-soft/45 px-4 py-3 text-left sm:px-5"
                aria-label="Espace de travail Drclick sécurisé"
            >
                <img
                    src="/brands/Appointment%20confirmed%20with%20a%20teal%20checkmark-6.png"
                    alt=""
                    class="size-[4.5rem] shrink-0 object-contain"
                    fetchpriority="high"
                />
                <div class="min-w-0">
                    <p
                        class="text-[11px] font-extrabold tracking-[0.14em] text-brand uppercase"
                    >
                        Votre cabinet, prêt à travailler
                    </p>
                    <p class="mt-1 text-xs leading-5 text-slate-600">
                        Accédez à vos patients, rendez-vous et dossiers dans un
                        espace protégé.
                    </p>
                </div>
            </section>

            <Card class="w-full border-border/70 shadow-sm">
                <CardHeader class="space-y-2 px-6 pt-7 pb-5 sm:px-8 sm:pt-8">
                    <CardTitle
                        class="text-2xl leading-tight font-bold tracking-tight sm:text-3xl"
                    >
                        {{ title }}
                    </CardTitle>
                    <CardDescription class="text-sm leading-6">
                        {{ description }}
                    </CardDescription>
                </CardHeader>

                <CardContent class="px-6 pb-7 sm:px-8 sm:pb-8">
                    <slot />
                </CardContent>
            </Card>

            <p
                class="mt-5 inline-flex items-center gap-1.5 text-center text-xs text-muted-foreground"
            >
                <LockKeyhole class="size-3.5" aria-hidden="true" />
                Connexion sécurisée · Données médicales protégées
            </p>
        </div>
    </main>
</template>
