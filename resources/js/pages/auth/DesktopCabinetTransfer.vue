<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    CloudOff,
    LoaderCircle,
    LogIn,
    RotateCcw,
    Trash2,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { login } from '@/routes';

type Transfer = {
    status: 'running' | 'imported' | 'purged' | 'failed';
    phase: string | null;
    message: string | null;
    error: string | null;
    copied_rows: number;
    total_rows: number | null;
    copied_files: number;
    total_files: number | null;
    missing_files: number;
    summary: {
        patients: number;
        appointments: number;
        consultations: number;
        documents: number;
        payments: number;
        users: number;
    } | null;
    owner_email: string | null;
    transferred_at: string | null;
    purge_error: string | null;
};

const props = defineProps<{ transfer: Transfer }>();

defineOptions({
    layout: {
        title: 'Récupération des dossiers',
        description:
            'Les dossiers de votre cabinet sont copiés du service en ligne vers ce PC.',
    },
});

const transfer = ref<Transfer>(props.transfer);

// A form post (retry, purge) comes back with fresh props.
watch(
    () => props.transfer,
    (value) => {
        transfer.value = value;
    },
);
let timer: ReturnType<typeof setInterval> | null = null;

const poll = async (): Promise<void> => {
    try {
        const response = await fetch('/desktop/transfer/status', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (response.ok) {
            transfer.value = (await response.json()) as Transfer;
        }
    } catch {
        // The next tick tries again.
    }

    if (transfer.value.status !== 'running' && timer !== null) {
        clearInterval(timer);
        timer = null;
    }
};

onMounted(() => {
    if (transfer.value.status === 'running') {
        timer = setInterval(() => void poll(), 1500);
    }
});

onBeforeUnmount(() => {
    if (timer !== null) {
        clearInterval(timer);
    }
});

const percent = computed(() => {
    const { copied_rows, total_rows, copied_files, total_files } =
        transfer.value;
    const total = (total_rows ?? 0) + (total_files ?? 0);

    if (total === 0) {
        return transfer.value.phase === 'manifest' ? 0 : 100;
    }

    return Math.min(
        100,
        Math.round(((copied_rows + copied_files) / total) * 100),
    );
});

const transferredLabel = computed(() =>
    transfer.value.transferred_at
        ? new Date(transfer.value.transferred_at).toLocaleString('fr-DZ', {
              dateStyle: 'long',
              timeStyle: 'short',
          })
        : null,
);
</script>

<template>
    <Head title="Récupération des dossiers" />

    <div class="flex flex-col gap-6" data-test="desktop-transfer">
        <section
            v-if="transfer.status === 'running'"
            class="space-y-4"
            aria-live="polite"
        >
            <p class="flex items-center gap-2 font-semibold">
                <LoaderCircle class="size-5 animate-spin text-brand" />
                {{ transfer.message ?? 'Transfert en cours…' }}
            </p>
            <div
                class="h-2 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700"
                role="progressbar"
                :aria-valuenow="percent"
                aria-valuemin="0"
                aria-valuemax="100"
            >
                <div
                    class="h-full rounded-full bg-brand transition-all"
                    :style="{ width: `${percent}%` }"
                />
            </div>
            <p class="text-sm text-muted-foreground">
                {{ transfer.copied_rows }} /
                {{ transfer.total_rows ?? '…' }} enregistrements ·
                {{ transfer.copied_files }} /
                {{ transfer.total_files ?? '…' }} documents
            </p>
            <p class="text-sm text-muted-foreground">
                Gardez Drclick ouvert et ce PC connecté à Internet. Vous pouvez
                laisser cette page : le transfert continue.
            </p>
        </section>

        <section
            v-else-if="transfer.status === 'failed'"
            class="space-y-4"
            role="alert"
        >
            <p
                class="flex items-start gap-2 rounded-lg bg-red-50 p-4 text-sm text-red-800 dark:bg-red-950/40 dark:text-red-200"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                <span>{{ transfer.error }}</span>
            </p>
            <p class="text-sm text-muted-foreground">
                Rien n’a été supprimé du service en ligne : vos dossiers y sont
                toujours. Vous pouvez réessayer.
            </p>
            <div class="flex flex-wrap gap-3">
                <Form action="/desktop/transfer/retry" method="post">
                    <Button type="submit">
                        <RotateCcw class="size-4" />
                        Réessayer
                    </Button>
                </Form>
                <Form action="/desktop/transfer/cancel" method="post">
                    <Button type="submit" variant="outline">
                        Abandonner
                    </Button>
                </Form>
            </div>
        </section>

        <section v-else class="space-y-5">
            <p
                class="flex items-start gap-2 rounded-lg bg-emerald-50 p-4 text-sm text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100"
            >
                <CheckCircle2 class="mt-0.5 size-4 shrink-0" />
                <span>
                    Les dossiers du cabinet sont sur ce PC et ont été vérifiés.
                    Ce poste fonctionne désormais sans Internet.
                </span>
            </p>

            <dl
                v-if="transfer.summary"
                class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3"
            >
                <div class="rounded-lg border border-border p-3">
                    <dt class="text-muted-foreground">Patients</dt>
                    <dd class="text-lg font-bold">
                        {{ transfer.summary.patients }}
                    </dd>
                </div>
                <div class="rounded-lg border border-border p-3">
                    <dt class="text-muted-foreground">Consultations</dt>
                    <dd class="text-lg font-bold">
                        {{ transfer.summary.consultations }}
                    </dd>
                </div>
                <div class="rounded-lg border border-border p-3">
                    <dt class="text-muted-foreground">Rendez-vous</dt>
                    <dd class="text-lg font-bold">
                        {{ transfer.summary.appointments }}
                    </dd>
                </div>
                <div class="rounded-lg border border-border p-3">
                    <dt class="text-muted-foreground">Documents</dt>
                    <dd class="text-lg font-bold">
                        {{ transfer.summary.documents }}
                    </dd>
                </div>
                <div class="rounded-lg border border-border p-3">
                    <dt class="text-muted-foreground">Paiements</dt>
                    <dd class="text-lg font-bold">
                        {{ transfer.summary.payments }}
                    </dd>
                </div>
                <div class="rounded-lg border border-border p-3">
                    <dt class="text-muted-foreground">Utilisateurs</dt>
                    <dd class="text-lg font-bold">
                        {{ transfer.summary.users }}
                    </dd>
                </div>
            </dl>

            <p
                v-if="transfer.missing_files > 0"
                class="text-sm text-amber-700 dark:text-amber-300"
            >
                {{ transfer.missing_files }} document(s) étaient déjà absents du
                serveur et n’ont pas pu être copiés.
            </p>

            <div
                v-if="transfer.status === 'purged'"
                class="flex items-start gap-2 rounded-lg border border-border p-4 text-sm"
                data-test="desktop-transfer-purged"
            >
                <CloudOff class="mt-0.5 size-4 shrink-0 text-emerald-600" />
                <span>
                    La copie du serveur a été supprimée<template
                        v-if="transferredLabel"
                    >
                        le {{ transferredLabel }}</template
                    >. Les dossiers médicaux n’existent plus que sur ce PC.
                    Pensez aux sauvegardes (Configuration › Connexion &
                    sauvegardes).
                </span>
            </div>

            <div
                v-else
                class="space-y-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
            >
                <p class="font-semibold">
                    Supprimer la copie restée sur le serveur
                </p>
                <p>
                    Les consultations, ordonnances, documents, paiements et
                    données médicales seront effacés du service en ligne. Pour
                    l’application mobile, le serveur garde seulement le nom et
                    le téléphone des patients et leurs rendez-vous.
                </p>
                <Form
                    action="/desktop/transfer/purge"
                    method="post"
                    v-slot="{ errors, processing }"
                    class="space-y-2"
                >
                    <Label for="purge-confirmation"
                        >Tapez SUPPRIMER pour confirmer</Label
                    >
                    <Input
                        id="purge-confirmation"
                        name="confirmation"
                        autocomplete="off"
                        class="max-w-60 bg-white dark:bg-slate-900"
                    />
                    <InputError :message="errors.confirmation" />
                    <InputError :message="transfer.purge_error ?? undefined" />
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="processing"
                    >
                        <Trash2 class="size-4" />
                        Supprimer la copie du serveur
                    </Button>
                </Form>
            </div>

            <Button as-child variant="outline">
                <Link :href="login()">
                    <LogIn class="size-4" />
                    Se connecter
                    <template v-if="transfer.owner_email">
                        ({{ transfer.owner_email }})</template
                    >
                </Link>
            </Button>
            <p class="text-xs text-muted-foreground">
                Connectez-vous avec le même e-mail et le même mot de passe que
                sur le service en ligne. Les autres comptes du cabinet aussi.
            </p>
        </section>
    </div>
</template>
