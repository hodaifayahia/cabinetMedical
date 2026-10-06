<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    Copy,
    FolderOpen,
    HardDrive,
    LoaderCircle,
    Save,
    ShieldCheck,
} from '@lucide/vue';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    backupFolderPickerAvailable,
    pickBackupFolder,
} from '@/lib/backupFolderPicker';
import { postJson } from '@/lib/http';
import { inAppRestoreErrorMessage } from '@/pages/configuration/inAppRestoreContract';
import type { BackupDestinationStatus } from '@/types';

const props = defineProps<{
    destination: BackupDestinationStatus;
    disabled?: boolean;
}>();

const form = useForm({
    copy_directory: props.destination.copy_directory ?? '',
    copy_keep: props.destination.copy_keep,
});
const canBrowse = backupFolderPickerAvailable();
const browsing = ref(false);
const testing = ref(false);
const testResult = ref<{ ok: boolean; message: string } | null>(null);
const copiedLocation = ref(false);

watch(
    () => props.destination.copy_directory,
    (directory) => {
        if (!form.isDirty) {
            form.defaults({
                copy_directory: directory ?? '',
                copy_keep: props.destination.copy_keep,
            });
            form.reset();
        }
    },
);
watch(
    () => form.copy_directory,
    () => (testResult.value = null),
);

const formatDate = (value: string | null | undefined): string => {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? '—'
        : date.toLocaleString('fr-FR', {
              day: 'numeric',
              month: 'long',
              hour: '2-digit',
              minute: '2-digit',
              hour12: false,
          });
};

const browse = async () => {
    browsing.value = true;

    try {
        const pick = await pickBackupFolder();

        if (pick.status === 'picked') {
            form.copy_directory = pick.path;
        } else if (pick.status === 'unavailable') {
            testResult.value = {
                ok: false,
                message:
                    'Le sélecteur de dossier n’est pas disponible dans cette version : saisissez le chemin du dossier (par exemple D:\\Sauvegardes Drclick).',
            };
        }
    } finally {
        browsing.value = false;
    }
};

const testLocation = async () => {
    if (form.copy_directory.trim() === '') {
        return;
    }

    testing.value = true;
    testResult.value = null;

    try {
        testResult.value = await postJson<{ ok: boolean; message: string }>(
            '/app/configuration/backup/destination/test',
            { copy_directory: form.copy_directory.trim() },
        );
    } catch (error) {
        testResult.value = {
            ok: false,
            message: inAppRestoreErrorMessage(
                error,
                'Le dossier n’a pas pu être testé. Réessayez.',
            ),
        };
    } finally {
        testing.value = false;
    }
};

const save = () => {
    form.transform((data) => ({
        copy_directory: data.copy_directory.trim(),
        copy_keep: Number(data.copy_keep) || 1,
    })).put('/app/configuration/backup/destination', {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults();
            testResult.value = null;
        },
    });
};

const copyInternalLocation = async () => {
    if (!props.destination.internal_location) {
        return;
    }

    try {
        await navigator.clipboard.writeText(
            props.destination.internal_location,
        );
        copiedLocation.value = true;
        window.setTimeout(() => (copiedLocation.value = false), 2000);
    } catch {
        copiedLocation.value = false;
    }
};
</script>

<template>
    <article
        id="backup-location"
        class="scroll-mt-24 rounded-xl border border-slate-200 bg-white/70 p-5 dark:border-slate-800 dark:bg-slate-900/40"
    >
        <h3 class="flex items-center gap-2 font-semibold">
            <FolderOpen class="size-4 text-brand" />
            Emplacement des sauvegardes
        </h3>
        <p class="mt-1 text-sm text-muted-foreground">
            Drclick garde toujours ses sauvegardes dans son propre dossier sur
            ce PC. Ajoutez un second emplacement (autre disque, clé USB, dossier
            synchronisé) : chaque nouvelle sauvegarde y sera aussi copiée, puis
            vérifiée octet par octet.
        </p>

        <dl class="mt-4 grid gap-1 text-sm">
            <dt class="text-muted-foreground">
                Dossier de Drclick (sur ce PC)
            </dt>
            <dd class="flex flex-wrap items-center gap-2">
                <span
                    class="min-w-0 font-mono text-xs break-all"
                    :title="destination.internal_location ?? undefined"
                >
                    {{ destination.internal_location ?? '—' }}
                </span>
                <button
                    v-if="destination.internal_location"
                    type="button"
                    class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-700 hover:underline dark:text-emerald-300"
                    @click="copyInternalLocation"
                >
                    <Copy class="size-3" />
                    {{ copiedLocation ? 'Copié' : 'Copier' }}
                </button>
            </dd>
        </dl>

        <form class="mt-4 space-y-3" @submit.prevent="save">
            <div class="grid gap-2">
                <Label for="backup-copy-directory">
                    Copier aussi chaque sauvegarde dans ce dossier
                </Label>
                <div class="flex flex-wrap gap-2">
                    <Input
                        id="backup-copy-directory"
                        v-model="form.copy_directory"
                        class="min-w-0 flex-1 basis-64 font-mono text-xs"
                        maxlength="1024"
                        placeholder="Ex. : D:\Sauvegardes Drclick ou E:\ (clé USB)"
                        autocomplete="off"
                        spellcheck="false"
                        :disabled="disabled || form.processing"
                    />
                    <Button
                        v-if="canBrowse"
                        type="button"
                        variant="outline"
                        :disabled="disabled || browsing || form.processing"
                        @click="browse"
                    >
                        <LoaderCircle
                            v-if="browsing"
                            class="size-4 animate-spin"
                        />
                        <FolderOpen v-else class="size-4" />
                        Parcourir…
                    </Button>
                </div>
                <InputError :message="form.errors.copy_directory" />
            </div>
            <div class="grid max-w-xs gap-2">
                <Label for="backup-copy-keep">Copies conservées</Label>
                <Input
                    id="backup-copy-keep"
                    v-model.number="form.copy_keep"
                    type="number"
                    min="1"
                    max="365"
                    :disabled="disabled || form.processing"
                />
                <p class="text-xs text-muted-foreground">
                    Les copies Drclick les plus anciennes de ce dossier sont
                    supprimées au-delà de ce nombre; les autres fichiers ne sont
                    jamais touchés.
                </p>
                <InputError :message="form.errors.copy_keep" />
            </div>
            <p
                class="flex gap-2 rounded-lg bg-amber-50 p-3 text-xs text-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                Ces copies contiennent les dossiers médicaux sans chiffrement :
                choisissez un disque ou une clé que vous gardez en lieu sûr.
                Laissez le champ vide pour ne garder les sauvegardes que dans le
                dossier de Drclick.
            </p>
            <div class="flex flex-wrap gap-2">
                <Button
                    type="submit"
                    :disabled="disabled || form.processing || !form.isDirty"
                >
                    <LoaderCircle
                        v-if="form.processing"
                        class="size-4 animate-spin"
                    />
                    <Save v-else class="size-4" />
                    Enregistrer
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="
                        disabled || testing || form.copy_directory.trim() === ''
                    "
                    @click="testLocation"
                >
                    <LoaderCircle v-if="testing" class="size-4 animate-spin" />
                    <ShieldCheck v-else class="size-4" />
                    Tester l’emplacement
                </Button>
            </div>
            <p
                v-if="testResult"
                class="text-xs"
                :class="
                    testResult.ok
                        ? 'text-emerald-700 dark:text-emerald-300'
                        : 'text-red-700 dark:text-red-300'
                "
                role="status"
            >
                {{ testResult.message }}
            </p>
        </form>

        <div
            v-if="destination.copy_directory && destination.last_copy"
            class="mt-4 flex gap-2 border-t border-slate-200 pt-4 text-xs dark:border-slate-800"
            :class="
                destination.last_copy.status === 'success'
                    ? 'text-emerald-800 dark:text-emerald-300'
                    : 'text-red-700 dark:text-red-300'
            "
            role="status"
        >
            <CheckCircle2
                v-if="destination.last_copy.status === 'success'"
                class="size-4 shrink-0"
            />
            <AlertTriangle v-else class="size-4 shrink-0" />
            <span v-if="destination.last_copy.status === 'success'">
                Dernière copie vérifiée :
                <strong>{{ destination.last_copy.filename }}</strong> ·
                {{ formatDate(destination.last_copy.at) }}
            </span>
            <span v-else>
                Échec de la dernière copie ({{
                    formatDate(destination.last_copy.at)
                }}) : {{ destination.last_copy.message }} La sauvegarde reste
                disponible dans le dossier de Drclick.
            </span>
        </div>
        <p
            v-else-if="destination.copy_directory"
            class="mt-4 flex gap-2 border-t border-slate-200 pt-4 text-xs text-muted-foreground dark:border-slate-800"
        >
            <HardDrive class="size-4 shrink-0" />
            La prochaine sauvegarde sera aussi copiée dans ce dossier.
        </p>
    </article>
</template>
