<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { AlertTriangle, DatabaseBackup, Upload } from '@lucide/vue';
import { computed, ref } from 'vue';
import AuthBackLink from '@/components/auth/AuthBackLink.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { home } from '@/routes';

defineOptions({
    layout: {
        title: 'Restaurer une sauvegarde',
        description:
            'Retrouvez votre cabinet sur ce PC à partir d’une sauvegarde Drclick.',
    },
});

const props = defineProps<{
    maximumBytes: number;
}>();

const form = useForm<{
    backup: File | null;
    passphrase: string;
    confirmed: boolean;
}>({
    backup: null,
    passphrase: '',
    confirmed: false,
});
const fileInput = ref<HTMLInputElement | null>(null);
const clientError = ref<string | null>(null);

const selectedSize = computed(() => {
    const size = form.backup?.size ?? 0;

    return size >= 1024 * 1024 * 1024
        ? `${(size / (1024 * 1024 * 1024)).toFixed(1)} Go`
        : `${Math.max(1, Math.round(size / (1024 * 1024)))} Mo`;
});

const chooseFile = (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    clientError.value = null;

    if (file && !file.name.toLowerCase().endsWith('.msbackup')) {
        clientError.value =
            'Choisissez un fichier de sauvegarde Drclick (.msbackup).';
        form.backup = null;

        return;
    }

    if (file && file.size > props.maximumBytes) {
        clientError.value = 'Ce fichier dépasse la taille autorisée.';
        form.backup = null;

        return;
    }

    form.backup = file;
};

const submit = () => {
    if (!form.backup || !form.confirmed || form.processing) {
        return;
    }

    form.post('/desktop/restore-backup', {
        forceFormData: true,
        onFinish: () => form.reset('passphrase'),
    });
};
</script>

<template>
    <Head title="Restaurer une sauvegarde" />

    <AuthBackLink :href="home()" label="Retour au choix initial" />

    <div
        class="mb-7 flex gap-3 rounded-2xl border border-brand bg-brand-soft p-4 text-sm text-brand dark:border-brand dark:bg-brand-deep/35 dark:text-brand-soft"
        role="note"
    >
        <DatabaseBackup class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
        <p class="leading-6">
            Choisissez un fichier <strong>.msbackup</strong> : une sauvegarde
            copiée depuis l’ancien PC, exportée sur une clé USB ou téléchargée
            depuis Google Drive. Patients, consultations, documents et comptes
            reviennent tels qu’ils étaient ; vous vous connectez ensuite avec
            votre compte habituel.
        </p>
    </div>

    <form class="flex flex-col gap-6" @submit.prevent="submit">
        <div class="grid gap-2">
            <Label for="restore-backup-file" class="font-semibold">
                Fichier de sauvegarde
            </Label>
            <input
                id="restore-backup-file"
                ref="fileInput"
                type="file"
                accept=".msbackup"
                class="sr-only"
                :disabled="form.processing"
                @change="chooseFile"
            />
            <button
                type="button"
                class="flex min-h-12 items-center gap-3 rounded-xl border border-dashed border-input bg-background px-4 py-3 text-left text-sm transition hover:border-brand disabled:opacity-60"
                :disabled="form.processing"
                @click="fileInput?.click()"
            >
                <Upload class="size-4 shrink-0 text-brand" />
                <span v-if="form.backup" class="min-w-0">
                    <span class="block truncate font-semibold">
                        {{ form.backup.name }}
                    </span>
                    <span class="text-xs text-muted-foreground">
                        {{ selectedSize }}
                    </span>
                </span>
                <span v-else class="text-muted-foreground">
                    Choisir le fichier…
                </span>
            </button>
            <InputError :message="clientError ?? form.errors.backup" />
        </div>

        <div class="grid gap-2">
            <Label for="restore-passphrase" class="font-semibold">
                Phrase secrète
                <span class="font-normal text-muted-foreground">
                    (si la sauvegarde est chiffrée)
                </span>
            </Label>
            <PasswordInput
                id="restore-passphrase"
                v-model="form.passphrase"
                autocomplete="off"
                :disabled="form.processing"
            />
            <p class="text-xs text-muted-foreground">
                Les copies Google Drive et les exports sont chiffrés : utilisez
                la phrase secrète choisie à leur création.
            </p>
            <InputError :message="form.errors.passphrase" />
        </div>

        <label
            class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
        >
            <Checkbox
                :model-value="form.confirmed"
                :disabled="form.processing"
                @update:model-value="
                    (value) => (form.confirmed = value === true)
                "
            />
            <span class="leading-6">
                <AlertTriangle
                    class="mr-1 inline size-4 align-text-bottom text-amber-600"
                />
                Ce PC repartira entièrement de cette sauvegarde. Les connexions
                Google Drive et au service en ligne seront à refaire une fois
                connecté.
            </span>
        </label>
        <InputError :message="form.errors.confirmed" />

        <div v-if="form.processing" class="grid gap-2" role="status">
            <div class="h-2 overflow-hidden rounded-full bg-slate-200">
                <div
                    class="h-full rounded-full bg-brand transition-[width]"
                    :style="{ width: `${form.progress?.percentage ?? 5}%` }"
                />
            </div>
            <p class="text-xs text-muted-foreground">
                {{
                    (form.progress?.percentage ?? 0) < 100
                        ? 'Envoi du fichier…'
                        : 'Vérification et restauration en cours ; cela peut prendre quelques minutes. Ne fermez pas Drclick.'
                }}
            </p>
        </div>

        <Button
            type="submit"
            class="h-11"
            :disabled="!form.backup || !form.confirmed || form.processing"
        >
            <Spinner v-if="form.processing" />
            <DatabaseBackup v-else class="size-4" />
            Restaurer ce cabinet
        </Button>
    </form>
</template>
