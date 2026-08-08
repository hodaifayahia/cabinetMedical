<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Check,
    Clock3,
    LockKeyhole,
    RefreshCw,
    UserCheck,
    UsersRound,
} from '@lucide/vue';
import AuthBackLink from '@/components/auth/AuthBackLink.vue';
import { logout } from '@/routes';
import { awaitingApproval } from '@/routes/cabinet';

defineProps<{
    cabinet: { name: string } | null;
}>();

defineOptions({
    layout: {
        title: 'Demande transmise',
        description:
            'Votre profil est créé. Le responsable du cabinet doit encore valider votre accès.',
    },
});
</script>

<template>
    <Head title="Approbation du cabinet" />

    <AuthBackLink
        :href="logout()"
        method="post"
        as="button"
        label="Retour à la connexion"
    />

    <section class="text-center" aria-labelledby="approval-status-title">
        <div
            class="mx-auto flex size-20 items-center justify-center rounded-[1.6rem] bg-amber-50 text-amber-600 shadow-lg shadow-amber-900/5 dark:bg-amber-950/40 dark:text-amber-300"
        >
            <UserCheck class="size-9" aria-hidden="true" />
        </div>
        <p
            class="mt-5 text-xs font-bold tracking-[0.16em] text-amber-700 uppercase dark:text-amber-300"
        >
            En attente d’approbation
        </p>
        <h3
            id="approval-status-title"
            class="mt-2 text-xl font-extrabold text-slate-950 dark:text-white"
        >
            Le responsable doit confirmer votre rôle
        </h3>
        <p
            class="mx-auto mt-3 max-w-md text-sm leading-6 text-slate-500 dark:text-slate-400"
        >
            <template v-if="cabinet">
                Votre demande pour rejoindre
                <span class="font-semibold text-slate-700 dark:text-slate-200">
                    {{ cabinet.name }}
                </span>
                a bien été envoyée.
            </template>
            Dès que le propriétaire vous attribue un rôle, votre prochain accès
            vous conduit automatiquement au tableau de bord.
        </p>
    </section>

    <ol
        class="mt-8 grid gap-3 text-left sm:grid-cols-3"
        aria-label="Progression de la demande"
    >
        <li
            class="rounded-2xl border border-emerald-200 bg-emerald-50/70 p-4 dark:border-emerald-900 dark:bg-emerald-950/30"
        >
            <span
                class="flex size-7 items-center justify-center rounded-full bg-emerald-600 text-white"
            >
                <Check class="size-4" aria-hidden="true" />
            </span>
            <p class="mt-3 text-sm font-bold text-slate-800 dark:text-white">
                Profil créé
            </p>
            <p
                class="mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400"
            >
                Votre demande a été transmise.
            </p>
        </li>
        <li
            class="rounded-2xl border border-amber-200 bg-amber-50/70 p-4 ring-2 ring-amber-100 dark:border-amber-900 dark:bg-amber-950/30 dark:ring-amber-950"
            aria-current="step"
        >
            <span
                class="flex size-7 items-center justify-center rounded-full bg-amber-500 text-white"
            >
                <Clock3 class="size-4" aria-hidden="true" />
            </span>
            <p class="mt-3 text-sm font-bold text-slate-800 dark:text-white">
                Approbation
            </p>
            <p
                class="mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400"
            >
                Le propriétaire choisit votre rôle.
            </p>
        </li>
        <li
            class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4 dark:border-slate-700 dark:bg-slate-800/40"
        >
            <span
                class="flex size-7 items-center justify-center rounded-full bg-slate-200 text-slate-500 dark:bg-slate-700 dark:text-slate-300"
            >
                <LockKeyhole class="size-4" aria-hidden="true" />
            </span>
            <p class="mt-3 text-sm font-bold text-slate-800 dark:text-white">
                Accès cabinet
            </p>
            <p
                class="mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400"
            >
                Disponible après validation.
            </p>
        </li>
    </ol>

    <Link
        :href="awaitingApproval()"
        class="mt-8 inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-[#1268a5] px-5 text-sm font-bold text-white shadow-lg shadow-sky-800/10 hover:bg-[#0d578b]"
        data-test="refresh-approval"
    >
        <RefreshCw class="size-4" aria-hidden="true" />
        Vérifier mon accès
    </Link>

    <p
        class="mt-5 flex items-center justify-center gap-2 text-center text-xs leading-5 text-slate-400"
    >
        <UsersRound class="size-4 shrink-0" aria-hidden="true" />
        Votre demande réserve déjà une place dans l’équipe.
    </p>
</template>
