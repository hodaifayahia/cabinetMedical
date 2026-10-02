<script setup lang="ts">
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { invoke, isTauri } from '@tauri-apps/api/core';

import { getJson, HttpError, postJson } from '@/lib/http';
import { useDesktopUpdateWatcher } from '@/lib/desktopUpdateWatcher';
import {
    normalizeNativeUpdateInstallResponse,
    normalizeUpdateInstallPreparation,
    signedUpdaterErrorMessage,
} from '@/pages/configuration/updateContract';

/**
 * Offers the signed desktop release to every account using this installation.
 * Installing takes a recent password confirmation, a verified safety backup,
 * and the native updater's signature check before the desktop restarts.
 */
const { available, dismissed, dismiss } = useDesktopUpdateWatcher();
const page = usePage();

const visible = computed(
    () =>
        page.props.desktopUpdateInstallAvailable === true &&
        available.value !== null &&
        !dismissed.value,
);
const preparing = ref(false);
const confirmingPassword = ref(false);
const passwordPrompt = ref(false);
const password = ref('');
const passwordError = ref<string | null>(null);
const updateError = ref<string | null>(null);
const updateNotice = ref<string | null>(null);
const targetVersion = ref<string | null>(null);

const getErrorMessage = (error: unknown): string => {
    if (error instanceof HttpError) {
        return error.message;
    }

    if (
        typeof error === 'object' &&
        error !== null &&
        'errors' in error &&
        typeof error.errors === 'object' &&
        error.errors !== null &&
        'password' in error.errors &&
        Array.isArray(error.errors.password) &&
        typeof error.errors.password[0] === 'string'
    ) {
        return error.errors.password[0];
    }

    return signedUpdaterErrorMessage(error);
};

const installUpdate = async (version: string): Promise<void> => {
    preparing.value = true;
    updateError.value = null;
    updateNotice.value = 'Création et vérification de la sauvegarde de sécurité…';

    try {
        const preparation = normalizeUpdateInstallPreparation(
            await postJson<unknown>('/app/configuration/updates/prepare-install', {
                target_version: version,
            }),
        );

        if (
            !preparation ||
            preparation.authorization.target_version !== version
        ) {
            throw new Error('invalid_update_install_preparation');
        }

        updateNotice.value = `Sauvegarde vérifiée. Téléchargement et vérification de la version ${version}…`;

        const result = normalizeNativeUpdateInstallResponse(
            await invoke<unknown>('install_signed_update', {
                authorization: preparation.authorization,
            }),
        );

        if (!result || result.target_version !== version) {
            throw new Error('invalid_update_install_result');
        }

        updateNotice.value = result.message_fr;
        passwordPrompt.value = false;
        password.value = '';
    } catch (error) {
        if (error instanceof HttpError && error.status === 423) {
            passwordPrompt.value = true;
            passwordError.value = null;
            updateNotice.value = null;
        } else {
            updateError.value = getErrorMessage(error);
            updateNotice.value = null;
        }
    } finally {
        preparing.value = false;
    }
};

const startUpdate = async (): Promise<void> => {
    if (!isTauri() || available.value === null || preparing.value) {
        return;
    }

    const version = available.value.version;
    targetVersion.value = version;
    updateError.value = null;
    passwordError.value = null;

    if (
        !window.confirm(
            `Installer Drclick ${version} sur ce poste ? Une sauvegarde locale vérifiée sera créée avant l'installation. Le poste redémarrera et tous ses comptes retrouveront la nouvelle version au redémarrage.`,
        )
    ) {
        return;
    }

    try {
        const status = await getJson<{ confirmed?: unknown }>(
            '/user/confirmed-password-status?seconds=10800',
        );

        if (status.confirmed !== true) {
            passwordPrompt.value = true;

            return;
        }
    } catch {
        // The protected prepare-install request below remains the authority;
        // if the status endpoint is temporarily unavailable it returns a
        // precise error or asks for password confirmation.
    }

    await installUpdate(version);
};

const confirmPasswordAndInstall = async (): Promise<void> => {
    const version = targetVersion.value;

    if (!version || password.value.length === 0 || confirmingPassword.value) {
        return;
    }

    confirmingPassword.value = true;
    passwordError.value = null;

    try {
        await postJson<unknown>('/user/confirm-password', {
            password: password.value,
        });
        password.value = '';
        await installUpdate(version);
    } catch (error) {
        passwordError.value = getErrorMessage(error);
    } finally {
        confirmingPassword.value = false;
    }
};
</script>

<template>
    <div
        v-if="visible"
        role="status"
        aria-live="polite"
        class="flex flex-wrap items-center gap-3 border-b border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100"
    >
        <span class="font-medium">
            La version {{ available?.version }} est disponible.
        </span>

        <span v-if="updateNotice" class="basis-full sm:basis-auto">
            {{ updateNotice }}
        </span>

        <p
            v-if="updateError"
            class="basis-full text-red-800 dark:text-red-200"
            role="alert"
        >
            {{ updateError }}
        </p>

        <form
            v-if="passwordPrompt"
            class="flex w-full flex-wrap items-end gap-2 rounded-lg border border-emerald-200 bg-white/70 p-3 dark:border-emerald-800 dark:bg-emerald-900/30"
            @submit.prevent="confirmPasswordAndInstall"
        >
            <label class="grid min-w-56 flex-1 gap-1">
                <span class="text-xs font-semibold">Confirmez votre mot de passe</span>
                <input
                    v-model="password"
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    required
                    class="h-9 rounded-md border border-emerald-300 bg-white px-3 text-sm text-slate-900 outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 dark:border-emerald-700 dark:bg-slate-950 dark:text-white"
                />
                <span v-if="passwordError" class="text-xs text-red-700 dark:text-red-300">
                    {{ passwordError }}
                </span>
            </label>
            <button
                type="submit"
                :disabled="confirmingPassword || preparing || password.length === 0"
                class="rounded-md bg-emerald-700 px-3 py-2 text-xs font-semibold text-white transition hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-60"
            >
                {{ confirmingPassword || preparing ? 'Préparation…' : 'Confirmer et installer' }}
            </button>
            <button
                type="button"
                :disabled="confirmingPassword || preparing"
                class="rounded-md px-3 py-2 text-xs font-medium underline underline-offset-2 disabled:opacity-60"
                @click="passwordPrompt = false; passwordError = null; password = ''"
            >
                Annuler
            </button>
        </form>

        <button
            v-else
            type="button"
            :disabled="preparing"
            class="rounded-md bg-emerald-700 px-3 py-1 font-semibold text-white transition hover:bg-emerald-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
            @click="startUpdate"
        >
            {{ preparing ? 'Préparation…' : 'Mettre à jour maintenant' }}
        </button>

        <button
            v-if="!preparing && !passwordPrompt"
            type="button"
            class="ml-auto rounded-md px-2 py-1 underline underline-offset-2 transition hover:bg-emerald-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 dark:hover:bg-emerald-900"
            @click="dismiss"
        >
            Plus tard
        </button>
    </div>
</template>
