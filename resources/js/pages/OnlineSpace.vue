<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    CheckCircle2,
    Download,
    HardDrive,
    KeyRound,
    MonitorSmartphone,
    ShieldCheck,
    UserCog,
} from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

type Props = {
    cabinet: {
        name: string;
        is_owner: boolean;
        transferred_at: string | null;
    } | null;
    recordsOnline: {
        patients: number;
        consultations: number;
        documents: number;
    } | null;
    download: {
        available: boolean;
        url: string | null;
        label: string;
    };
};

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Espace cabinet', href: '/espace-cabinet' }],
    },
});

const recordsLeft = computed(() => {
    const records = props.recordsOnline;

    return records === null
        ? 0
        : records.patients + records.consultations + records.documents;
});

const transferredLabel = computed(() =>
    props.cabinet?.transferred_at
        ? new Date(props.cabinet.transferred_at).toLocaleString('fr-DZ', {
              dateStyle: 'long',
              timeStyle: 'short',
          })
        : null,
);
</script>

<template>
    <Head title="Espace cabinet" />

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
        <Heading
            :title="cabinet?.name ?? 'Espace cabinet'"
            description="Votre compte en ligne Drclick : licence, crédits IA, utilisateurs et application mobile."
        />

        <section class="med-panel p-6" data-test="online-space-records">
            <h2
                class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"
            >
                <HardDrive class="size-5 text-emerald-600" />
                Vos dossiers patients sont sur le PC du cabinet
            </h2>
            <p class="mt-2 text-sm text-muted-foreground">
                Pour respecter la protection des données personnelles, les
                patients, consultations, ordonnances et documents sont
                enregistrés uniquement dans l’application Drclick installée sur
                le PC du cabinet. Ils ne sont pas gérés depuis ce site.
            </p>

            <div
                v-if="transferredLabel"
                class="mt-4 flex items-start gap-3 rounded-lg bg-emerald-50 p-4 text-sm text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100"
            >
                <CheckCircle2 class="mt-0.5 size-4 shrink-0" />
                <p>
                    Les dossiers de ce cabinet ont été transférés sur son PC le
                    {{ transferredLabel }}. Il n’en reste plus sur le serveur.
                </p>
            </div>

            <div
                v-else-if="recordsOnline && recordsLeft > 0"
                class="mt-4 space-y-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
                data-test="online-space-records-left"
            >
                <p class="font-semibold">
                    Ce cabinet a encore des dossiers sur le serveur :
                    {{ recordsOnline.patients }} patient(s),
                    {{ recordsOnline.consultations }} consultation(s),
                    {{ recordsOnline.documents }} document(s).
                </p>
                <p>Pour les récupérer sur le PC du cabinet :</p>
                <ol class="list-decimal space-y-1 pl-5">
                    <li>
                        Installez l’application Drclick sur le PC (bouton
                        ci-dessous).
                    </li>
                    <li>
                        Au premier lancement, choisissez « Cabinet existant »,
                        saisissez l’e-mail et le mot de passe du médecin
                        titulaire et laissez cochée « Récupérer les dossiers
                        enregistrés en ligne ».
                    </li>
                    <li>
                        Une fois les dossiers vérifiés sur le PC, l’application
                        propose de supprimer la copie du serveur.
                    </li>
                </ol>
                <p v-if="cabinet && !cabinet.is_owner">
                    Seul le médecin titulaire du cabinet peut faire ce
                    transfert.
                </p>
            </div>

            <div class="mt-5 flex flex-wrap gap-3">
                <Button v-if="download.available && download.url" as-child>
                    <a :href="download.url">
                        <Download class="size-4" />
                        Télécharger l’application Drclick
                    </a>
                </Button>
                <p v-else class="text-sm text-muted-foreground">
                    Le téléchargement de l’application sera bientôt disponible
                    ici. Contactez l’administration Drclick.
                </p>
            </div>
        </section>

        <section class="grid gap-4 md:grid-cols-3">
            <Link
                href="/app/configuration/connectivity-backup"
                class="med-panel flex flex-col gap-2 p-5 transition hover:border-brand/40"
            >
                <KeyRound class="size-5 text-brand" />
                <span class="font-semibold text-slate-900 dark:text-white"
                    >Licence</span
                >
                <span class="text-sm text-muted-foreground"
                    >Type, validité et état de la licence du cabinet.</span
                >
            </Link>
            <Link
                href="/app/staff"
                class="med-panel flex flex-col gap-2 p-5 transition hover:border-brand/40"
            >
                <UserCog class="size-5 text-brand" />
                <span class="font-semibold text-slate-900 dark:text-white"
                    >Utilisateurs</span
                >
                <span class="text-sm text-muted-foreground"
                    >Comptes du cabinet et places disponibles.</span
                >
            </Link>
            <div class="med-panel flex flex-col gap-2 p-5">
                <MonitorSmartphone class="size-5 text-brand" />
                <span class="font-semibold text-slate-900 dark:text-white"
                    >Application mobile et IA</span
                >
                <span class="text-sm text-muted-foreground"
                    >Les rendez-vous pris sur mobile et l’assistant IA passent
                    par ce compte, puis arrivent sur le PC du cabinet.</span
                >
            </div>
        </section>

        <p class="flex items-start gap-2 text-xs text-muted-foreground">
            <ShieldCheck class="mt-0.5 size-4 shrink-0" />
            L’assistant IA reçoit des informations médicales sans nom, téléphone
            ni adresse du patient. Les rendez-vous de l’application mobile
            transitent par ce serveur.
        </p>
    </div>
</template>
