<script setup lang="ts">
import {
    Cloud,
    DatabaseBackup,
    Download,
    FolderCheck,
    Lock,
    RefreshCw,
    ShieldCheck,
} from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { driveUploadStatusLabel } from '@/pages/configuration/driveUploadPresentation';
import { backupKindLabel } from '@/pages/configuration/inAppRestoreContract';
import type { LocalBackupEntry } from '@/types';

defineProps<{
    entries: LocalBackupEntry[];
    /** Downloads require a recent password confirmation. */
    canDownload: boolean;
    canRestore: boolean;
    copyFolderConfigured: boolean;
}>();

const emit = defineEmits<{ restore: [entry: LocalBackupEntry] }>();

const formatDate = (value: string | null): string => {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? '—'
        : date.toLocaleString('fr-FR', {
              weekday: 'short',
              day: 'numeric',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
              hour12: false,
          });
};

const formatBytes = (bytes: number): string => {
    if (bytes < 1024 * 1024) {
        return (
            new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 }).format(
                bytes / 1024,
            ) + ' Kio'
        );
    }

    return (
        new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 }).format(
            bytes / (1024 * 1024),
        ) + ' Mio'
    );
};

const downloadUrl = (entry: LocalBackupEntry): string =>
    '/app/configuration/backup/archives/download?archive=' +
    encodeURIComponent(entry.key);
</script>

<template>
    <section id="latest-backups" class="med-panel scroll-mt-24 overflow-hidden">
        <div class="border-b border-sidebar-border/70 px-6 py-5">
            <h2
                class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"
            >
                <DatabaseBackup class="size-5 text-brand" />
                Dernières sauvegardes
            </h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Les sauvegardes présentes sur ce PC, de la plus récente à la
                plus ancienne. Téléchargez-en une pour la garder ailleurs, ou
                restaurez-la pour revenir à cet état.
            </p>
        </div>
        <p
            v-if="entries.length === 0"
            class="px-6 py-8 text-sm text-muted-foreground"
        >
            Aucune sauvegarde sur ce PC pour l’instant. Utilisez « Sauvegarder
            maintenant » pour en créer une.
        </p>
        <div v-else class="overflow-x-auto">
            <table class="med-table min-w-full">
                <thead
                    class="bg-muted/40 text-left text-xs tracking-wide text-muted-foreground uppercase"
                >
                    <tr>
                        <th class="px-4 py-3 font-medium">Date</th>
                        <th class="px-4 py-3 font-medium">Type</th>
                        <th class="px-4 py-3 font-medium">Taille</th>
                        <th class="px-4 py-3 font-medium">Emplacements</th>
                        <th class="px-4 py-3 font-medium">État</th>
                        <th class="px-4 py-3 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-sidebar-border/70">
                    <tr v-for="entry in entries" :key="entry.key">
                        <td class="px-4 py-3">
                            <span class="block font-medium">
                                {{ formatDate(entry.created_at) }}
                            </span>
                            <span
                                class="block max-w-72 truncate font-mono text-[11px] text-muted-foreground"
                                :title="entry.filename"
                            >
                                {{ entry.filename }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            {{ backupKindLabel(entry.kind) }}
                            <Lock
                                v-if="entry.encrypted"
                                class="ml-1 inline size-3.5 text-muted-foreground"
                                aria-label="Chiffrée"
                            />
                        </td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">
                            {{ formatBytes(entry.size_bytes) }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1.5 text-xs">
                                <span
                                    class="inline-flex items-center gap-1 rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 font-semibold text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300"
                                >
                                    <DatabaseBackup class="size-3" /> Ce PC
                                </span>
                                <span
                                    v-if="entry.in_copy_folder"
                                    class="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300"
                                >
                                    <FolderCheck class="size-3" /> Copie
                                </span>
                                <span
                                    v-else-if="copyFolderConfigured"
                                    class="inline-flex items-center gap-1 rounded-full border border-slate-200 px-2 py-0.5 text-muted-foreground dark:border-slate-700"
                                >
                                    Pas de copie
                                </span>
                                <span
                                    v-if="entry.drive_status"
                                    class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 font-semibold"
                                    :class="
                                        entry.drive_status === 'completed'
                                            ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300'
                                            : entry.drive_status === 'failed'
                                              ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300'
                                              : 'border-brand bg-brand-soft text-brand dark:border-brand dark:bg-brand-deep/30 dark:text-brand-mint'
                                    "
                                    :title="
                                        driveUploadStatusLabel(
                                            entry.drive_status,
                                        )
                                    "
                                >
                                    <Cloud class="size-3" />
                                    Drive ·
                                    {{
                                        driveUploadStatusLabel(
                                            entry.drive_status,
                                        )
                                    }}
                                </span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <span
                                class="inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-semibold"
                                :class="
                                    entry.verified
                                        ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300'
                                        : 'border-slate-200 bg-slate-100 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300'
                                "
                            >
                                <ShieldCheck
                                    v-if="entry.verified"
                                    class="size-3"
                                />
                                {{ entry.verified ? 'Vérifiée' : 'Non suivie' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-2">
                                <a
                                    v-if="canDownload"
                                    :href="downloadUrl(entry)"
                                    class="inline-flex h-8 items-center justify-center gap-1.5 rounded-lg border border-input bg-background px-3 text-xs font-medium hover:bg-accent"
                                >
                                    <Download class="size-3.5" />
                                    Télécharger
                                </a>
                                <Button
                                    v-if="canRestore"
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    class="border-amber-300 text-amber-800 hover:bg-amber-50 dark:border-amber-900 dark:text-amber-300"
                                    @click="emit('restore', entry)"
                                >
                                    <RefreshCw class="size-3.5" />
                                    Restaurer
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p
            v-if="entries.length > 0 && !canDownload"
            class="border-t border-sidebar-border/70 px-6 py-3 text-xs text-muted-foreground"
        >
            Confirmez votre mot de passe (en haut de la page) pour télécharger
            une sauvegarde.
        </p>
    </section>
</template>
