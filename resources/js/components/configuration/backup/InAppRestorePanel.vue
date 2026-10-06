<script setup lang="ts">
import {
    AlertTriangle,
    LoaderCircle,
    RefreshCw,
    ShieldCheck,
    Upload,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { deleteJson, postFormData, postJson } from '@/lib/http';
import {
    backupKindLabel,
    inAppRestoreErrorMessage,
    normalizeInAppRestorePreparation,
} from '@/pages/configuration/inAppRestoreContract';
import type {
    ConfigurationCapability,
    InAppRestorePreparation,
    LocalBackupEntry,
} from '@/types';

const props = defineProps<{
    capability: ConfigurationCapability;
    /** Recent password confirmation (required by both steps). */
    confirmed: boolean;
}>();

const fileInput = ref<HTMLInputElement | null>(null);
const file = ref<File | null>(null);
const archive = ref<LocalBackupEntry | null>(null);
const passphrase = ref('');
const preparing = ref(false);
const applying = ref(false);
const preparation = ref<InAppRestorePreparation | null>(null);
const acknowledged = ref(false);
const typedConfirmation = ref('');
const error = ref<string | null>(null);
const panel = ref<HTMLElement | null>(null);

const sourceLabel = computed(
    () => archive.value?.filename ?? file.value?.name ?? null,
);
const passphraseUseful = computed(
    () => file.value !== null || archive.value?.encrypted === true,
);
const canPrepare = computed(
    () =>
        props.capability.available &&
        props.confirmed &&
        (file.value !== null || archive.value !== null) &&
        !preparing.value &&
        !applying.value,
);
const canApply = computed(
    () =>
        props.capability.available &&
        props.confirmed &&
        preparation.value !== null &&
        acknowledged.value &&
        typedConfirmation.value.trim().toUpperCase() ===
            preparation.value.confirmation &&
        !preparing.value &&
        !applying.value,
);

const formatDate = (value: string | null): string => {
    if (!value) {
        return 'date inconnue';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? value
        : date.toLocaleString('fr-FR', {
              weekday: 'long',
              day: 'numeric',
              month: 'long',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
              hour12: false,
          });
};

const resetPreparation = () => {
    if (preparation.value !== null) {
        void deleteJson('/app/configuration/backup/archives/restore').catch(
            () => undefined,
        );
    }

    preparation.value = null;
    acknowledged.value = false;
    typedConfirmation.value = '';
};

const clearSelection = () => {
    if (applying.value) {
        return;
    }

    resetPreparation();
    file.value = null;
    archive.value = null;
    passphrase.value = '';
    error.value = null;

    if (fileInput.value !== null) {
        fileInput.value.value = '';
    }
};

const chooseFile = (event: Event) => {
    const chosen = (event.target as HTMLInputElement).files?.[0] ?? null;
    resetPreparation();
    archive.value = null;
    error.value = null;
    file.value = null;

    if (chosen === null) {
        return;
    }

    if (!/\.msbackup$/i.test(chosen.name) || chosen.name.length > 255) {
        error.value =
            'Choisissez un fichier de sauvegarde Drclick (extension .msbackup).';

        return;
    }

    file.value = chosen;
};

/** Called by the "Restaurer" button of the latest-backups list. */
const selectArchive = (entry: LocalBackupEntry) => {
    clearSelection();
    archive.value = entry;
    panel.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

defineExpose({ selectArchive });

const prepare = async () => {
    if (!canPrepare.value) {
        return;
    }

    const data = new FormData();

    if (archive.value !== null) {
        data.append('archive', archive.value.key);
    } else if (file.value !== null) {
        data.append('backup', file.value, file.value.name);
    }

    if (passphrase.value !== '') {
        data.append('passphrase', passphrase.value);
    }

    passphrase.value = '';
    preparing.value = true;
    error.value = null;
    resetPreparation();

    try {
        const response = await postFormData<unknown>(
            '/app/configuration/backup/archives/restore/prepare',
            data,
        );
        const normalized = normalizeInAppRestorePreparation(response);

        if (normalized === null) {
            throw new Error('invalid_restore_preparation');
        }

        preparation.value = normalized;
    } catch (failure) {
        error.value = inAppRestoreErrorMessage(failure);
    } finally {
        data.delete('passphrase');
        preparing.value = false;
    }
};

const apply = async () => {
    if (!canApply.value || preparation.value === null) {
        return;
    }

    applying.value = true;
    error.value = null;

    try {
        const response = await postJson<{ redirect?: unknown }>(
            '/app/configuration/backup/archives/restore/apply',
            {
                operation_id: preparation.value.operation_id,
                confirmed: acknowledged.value,
                confirmation: typedConfirmation.value.trim().toUpperCase(),
            },
        );
        const redirect =
            typeof response?.redirect === 'string' &&
            response.redirect.startsWith(window.location.origin + '/')
                ? response.redirect
                : '/login';

        // Every session ended with the old data: a full reload signs in again.
        window.location.assign(redirect);
    } catch (failure) {
        applying.value = false;
        preparation.value = null;
        acknowledged.value = false;
        typedConfirmation.value = '';
        error.value = inAppRestoreErrorMessage(
            failure,
            'La sauvegarde n’a pas pu être restaurée. Les données actuelles ont été conservées.',
        );
    }
};
</script>

<template>
    <section id="restore-backup" ref="panel" class="med-panel scroll-mt-24 p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2
                    class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"
                >
                    <RefreshCw class="size-5 text-amber-600" />
                    Restaurer une sauvegarde
                </h2>
                <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                    Revenez à l’état d’une sauvegarde : choisissez-la dans la
                    liste ci-dessus, ou importez un fichier .msbackup (clé USB,
                    ancien PC, Google Drive). Drclick la vérifie et vous montre
                    son contenu avant de remplacer quoi que ce soit, et garde
                    une sauvegarde de sécurité des données actuelles.
                </p>
            </div>
            <span
                class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                :class="
                    capability.available
                        ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300'
                        : 'border-slate-200 bg-slate-100 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300'
                "
            >
                {{ capability.available ? 'Disponible' : 'Indisponible' }}
            </span>
        </div>

        <p
            v-if="!capability.available"
            class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-muted-foreground dark:border-slate-800 dark:bg-slate-950/30"
        >
            {{ capability.reason }}
        </p>

        <template v-else>
            <p
                v-if="!confirmed"
                class="mt-4 flex gap-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-950/30 dark:text-amber-200"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                Confirmez votre mot de passe (en haut de la page) avant de
                restaurer une sauvegarde.
            </p>

            <div class="mt-5 grid gap-4 lg:grid-cols-2">
                <div class="space-y-3">
                    <Label for="in-app-restore-file"
                        >Sauvegarde à restaurer</Label
                    >
                    <div
                        v-if="sourceLabel"
                        class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-800 dark:bg-slate-900"
                    >
                        <span class="min-w-0 flex-1 truncate font-medium">
                            {{ sourceLabel }}
                        </span>
                        <span
                            v-if="archive"
                            class="text-xs text-muted-foreground"
                        >
                            {{ backupKindLabel(archive.kind) }}
                        </span>
                        <button
                            type="button"
                            class="text-muted-foreground hover:text-foreground"
                            aria-label="Retirer"
                            :disabled="applying"
                            @click="clearSelection"
                        >
                            <X class="size-4" />
                        </button>
                    </div>
                    <label
                        for="in-app-restore-file"
                        class="inline-flex h-10 cursor-pointer items-center gap-2 rounded-xl border border-input bg-white px-3 text-sm font-medium dark:bg-slate-900"
                        :class="
                            (!confirmed || preparing || applying) &&
                            'pointer-events-none opacity-50'
                        "
                    >
                        <Upload class="size-4" />
                        Importer un fichier .msbackup
                    </label>
                    <input
                        id="in-app-restore-file"
                        ref="fileInput"
                        class="sr-only"
                        type="file"
                        accept=".msbackup"
                        :disabled="!confirmed || preparing || applying"
                        @change="chooseFile"
                    />
                </div>
                <div v-if="passphraseUseful" class="space-y-2">
                    <Label for="in-app-restore-passphrase">
                        Phrase secrète
                        <span class="font-normal text-muted-foreground">
                            (si la sauvegarde est chiffrée)
                        </span>
                    </Label>
                    <Input
                        id="in-app-restore-passphrase"
                        v-model="passphrase"
                        type="password"
                        maxlength="1024"
                        autocomplete="off"
                        :disabled="!confirmed || preparing || applying"
                    />
                </div>
            </div>

            <Button
                type="button"
                class="mt-4"
                variant="outline"
                :disabled="!canPrepare"
                @click="prepare"
            >
                <LoaderCircle v-if="preparing" class="size-4 animate-spin" />
                <ShieldCheck v-else class="size-4" />
                {{
                    preparing
                        ? 'Vérification de la sauvegarde…'
                        : 'Vérifier la sauvegarde'
                }}
            </Button>

            <div
                v-if="preparation"
                class="mt-5 rounded-xl border border-amber-300 bg-amber-50/70 p-5 text-sm dark:border-amber-900 dark:bg-amber-950/20"
            >
                <p
                    class="flex items-center gap-2 font-semibold text-emerald-800 dark:text-emerald-300"
                >
                    <ShieldCheck class="size-4" />
                    Sauvegarde vérifiée : intègre et lisible.
                </p>
                <dl class="mt-3 grid gap-x-6 gap-y-1 sm:grid-cols-2">
                    <div class="flex gap-2">
                        <dt class="text-muted-foreground">Créée le :</dt>
                        <dd class="font-medium">
                            {{ formatDate(preparation.summary.created_at) }}
                        </dd>
                    </div>
                    <div class="flex gap-2">
                        <dt class="text-muted-foreground">Cabinet :</dt>
                        <dd class="font-medium">
                            {{ preparation.summary.cabinet ?? '—' }}
                        </dd>
                    </div>
                    <div class="flex gap-2">
                        <dt class="text-muted-foreground">Patients :</dt>
                        <dd class="font-medium">
                            {{ preparation.summary.patients }}
                        </dd>
                    </div>
                    <div
                        v-if="preparation.summary.consultations !== null"
                        class="flex gap-2"
                    >
                        <dt class="text-muted-foreground">Consultations :</dt>
                        <dd class="font-medium">
                            {{ preparation.summary.consultations }}
                        </dd>
                    </div>
                    <div class="flex gap-2">
                        <dt class="text-muted-foreground">Comptes :</dt>
                        <dd class="font-medium">
                            {{ preparation.summary.users }}
                        </dd>
                    </div>
                    <div class="flex gap-2">
                        <dt class="text-muted-foreground">Documents :</dt>
                        <dd class="font-medium">
                            {{ preparation.summary.file_count }}
                        </dd>
                    </div>
                    <div
                        v-if="preparation.summary.application_version"
                        class="flex gap-2"
                    >
                        <dt class="text-muted-foreground">Version :</dt>
                        <dd class="font-medium">
                            {{ preparation.summary.application_version }}
                        </dd>
                    </div>
                </dl>
                <p
                    class="mt-4 flex gap-2 rounded-lg bg-white/80 p-3 text-amber-900 dark:bg-slate-900/60 dark:text-amber-200"
                >
                    <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                    Toutes les données actuelles de ce PC (patients,
                    consultations, documents, comptes) seront remplacées par
                    celles de cette sauvegarde. Une sauvegarde de sécurité des
                    données actuelles est créée juste avant. Tout le monde devra
                    se reconnecter.
                </p>
                <div class="mt-4 flex items-start gap-3">
                    <Checkbox
                        id="in-app-restore-acknowledged"
                        :model-value="acknowledged"
                        :disabled="applying"
                        @update:model-value="
                            (value) => (acknowledged = value === true)
                        "
                    />
                    <Label
                        for="in-app-restore-acknowledged"
                        class="leading-5 font-normal"
                    >
                        Je comprends que les données actuelles seront remplacées
                        par celles de cette sauvegarde.
                    </Label>
                </div>
                <div class="mt-3 grid max-w-sm gap-2">
                    <Label for="in-app-restore-confirmation">
                        Saisissez
                        <strong>{{ preparation.confirmation }}</strong>
                        pour confirmer
                    </Label>
                    <Input
                        id="in-app-restore-confirmation"
                        v-model="typedConfirmation"
                        autocomplete="off"
                        spellcheck="false"
                        :disabled="applying"
                    />
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    <Button
                        type="button"
                        class="bg-amber-600 text-white hover:bg-amber-700"
                        :disabled="!canApply"
                        @click="apply"
                    >
                        <LoaderCircle
                            v-if="applying"
                            class="size-4 animate-spin"
                        />
                        <RefreshCw v-else class="size-4" />
                        Restaurer cette sauvegarde
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="applying"
                        @click="clearSelection"
                    >
                        Annuler
                    </Button>
                </div>
            </div>

            <p
                v-if="error"
                class="mt-4 rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200"
                role="alert"
            >
                {{ error }}
            </p>
        </template>

        <div
            v-if="applying"
            class="fixed inset-0 z-[100] grid place-items-center bg-slate-950/90 p-6 text-white"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="in-app-restore-progress-title"
        >
            <div class="max-w-lg text-center">
                <LoaderCircle class="mx-auto size-12 animate-spin" />
                <h2
                    id="in-app-restore-progress-title"
                    class="mt-5 text-xl font-bold"
                >
                    Restauration en cours
                </h2>
                <p class="mt-3 text-sm leading-6 text-slate-200">
                    Drclick crée une sauvegarde de sécurité des données
                    actuelles, puis remet en place celles de la sauvegarde. Cela
                    peut prendre plusieurs minutes : n’éteignez pas cet
                    ordinateur et ne fermez pas l’application.
                </p>
            </div>
        </div>
    </section>
</template>
