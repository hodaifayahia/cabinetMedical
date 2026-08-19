<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import {
    KeyRound,
    LoaderCircle,
    LockKeyhole,
    MonitorCheck,
    ShieldCheck,
} from '@lucide/vue';
import { isTauri } from '@tauri-apps/api/core';
import { computed, nextTick, onMounted, ref } from 'vue';
import AuthBackLink from '@/components/auth/AuthBackLink.vue';
import InputError from '@/components/InputError.vue';
import { markDesktopOnboardingComplete } from '@/lib/desktopOnboarding';
import {
    defaultDesktopDeviceName,
    generateDesktopDeviceToken,
    isDesktopPinEnrollmentForUser,
    isValidDesktopPin,
    normalizeDesktopPin,
    readDesktopPinEnrollment,
    saveDesktopPinEnrollment,
} from '@/lib/desktopPin';
import { logout } from '@/routes';
import type { DesktopPinEnrollment } from '@/lib/desktopPin';

const desktopRuntime = ref(false);
const page = usePage();
const localEnrollment = ref<DesktopPinEnrollment | null>(null);
const localError = ref('');
const pinInput = ref<HTMLInputElement | null>(null);

const form = useForm({
    device_token: '',
    pin: '',
    pin_confirmation: '',
    device_name: 'Poste Drclick',
});

const authenticatedUser = computed(() => page.props.auth.user);
const shouldEnroll = computed(() => {
    const user = authenticatedUser.value;

    return (
        desktopRuntime.value &&
        Boolean(user?.can.enrollDesktopPin) &&
        !isDesktopPinEnrollmentForUser(localEnrollment.value, user?.id)
    );
});
const pin = computed({
    get: () => form.pin,
    set: (value: string) => {
        form.pin = normalizeDesktopPin(value);
        form.clearErrors('pin');
    },
});
const pinConfirmation = computed({
    get: () => form.pin_confirmation,
    set: (value: string) => {
        form.pin_confirmation = normalizeDesktopPin(value);
        form.clearErrors('pin_confirmation');
    },
});

function prepareDeviceToken(): boolean {
    if (form.device_token) {
        return true;
    }

    try {
        form.device_token = generateDesktopDeviceToken();
        localError.value = '';

        return true;
    } catch {
        localError.value =
            'La protection cryptographique de cet appareil est indisponible. Redémarrez Drclick puis réessayez.';

        return false;
    }
}

function validate(): boolean {
    form.clearErrors();
    localError.value = '';

    if (!isValidDesktopPin(form.pin)) {
        form.setError('pin', 'Saisissez exactement 4 chiffres.');

        return false;
    }

    if (form.pin_confirmation !== form.pin) {
        form.setError(
            'pin_confirmation',
            'La confirmation ne correspond pas au code PIN.',
        );

        return false;
    }

    form.device_name = form.device_name.trim();

    if (!form.device_name) {
        form.setError('device_name', 'Donnez un nom à cet appareil.');

        return false;
    }

    return prepareDeviceToken();
}

function enroll(): void {
    const enrollingUser = authenticatedUser.value;

    if (!enrollingUser?.can.enrollDesktopPin) {
        return;
    }

    if (!validate()) {
        void nextTick(() => pinInput.value?.focus());

        return;
    }

    form.post('/desktop/pin/enroll', {
        preserveScroll: true,
        onSuccess: () => {
            const saved = saveDesktopPinEnrollment(
                form.device_token,
                form.device_name,
                enrollingUser.id,
                enrollingUser.name,
            );

            form.reset('pin', 'pin_confirmation');

            if (!saved) {
                localError.value =
                    'Drclick ne peut pas mémoriser cet appareil. Vérifiez les autorisations de stockage puis redémarrez l’application.';

                return;
            }

            markDesktopOnboardingComplete();
            localEnrollment.value = readDesktopPinEnrollment();
        },
        onError: async () => {
            await nextTick();
            pinInput.value?.focus();
        },
    });
}

onMounted(async () => {
    desktopRuntime.value = isTauri();

    if (!desktopRuntime.value) {
        return;
    }

    localEnrollment.value = readDesktopPinEnrollment();

    if (!shouldEnroll.value) {
        return;
    }

    form.device_name = defaultDesktopDeviceName();
    prepareDeviceToken();
    await nextTick();
    pinInput.value?.focus();
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="shouldEnroll"
            class="fixed inset-0 z-[120] flex min-h-svh items-center justify-center overflow-y-auto bg-slate-950/75 p-4 backdrop-blur-md sm:p-6"
            data-test="desktop-pin-enrollment-overlay"
        >
            <section
                role="dialog"
                aria-modal="true"
                aria-labelledby="desktop-pin-title"
                aria-describedby="desktop-pin-description"
                class="relative my-auto w-full max-w-xl overflow-hidden rounded-[2rem] border border-white/70 bg-white shadow-[0_32px_100px_rgba(2,18,35,0.45)] dark:border-slate-700 dark:bg-slate-900"
            >
                <div
                    class="absolute inset-x-0 top-0 h-1.5 bg-brand-mint"
                    aria-hidden="true"
                />

                <div class="p-6 sm:p-9">
                    <AuthBackLink
                        :href="logout()"
                        method="post"
                        as="button"
                        label="Retour à la connexion"
                    />

                    <div class="flex items-start gap-4">
                        <span
                            class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-brand-deep text-white shadow-lg shadow-brand-deep/20"
                        >
                            <KeyRound class="size-7" aria-hidden="true" />
                        </span>
                        <div>
                            <p
                                class="text-xs font-extrabold tracking-[0.16em] text-brand uppercase"
                            >
                                Sécurisation de ce poste
                            </p>
                            <h2
                                id="desktop-pin-title"
                                class="mt-1 text-2xl leading-tight font-extrabold tracking-tight text-slate-950 sm:text-3xl dark:text-white"
                            >
                                Créez votre code PIN
                            </h2>
                        </div>
                    </div>

                    <p
                        id="desktop-pin-description"
                        class="mt-5 text-sm leading-6 text-slate-600 dark:text-slate-300"
                    >
                        Ce code à 4 chiffres permet d’ouvrir rapidement votre
                        cabinet sur cet ordinateur, sans ressaisir votre e-mail
                        ni votre mot de passe. Il reste lié uniquement à cette
                        installation Drclick.
                    </p>

                    <form
                        class="mt-7 space-y-5"
                        data-test="desktop-pin-enrollment-form"
                        @submit.prevent="enroll"
                    >
                        <input
                            type="hidden"
                            name="device_token"
                            :value="form.device_token"
                        />

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid min-w-0 gap-2">
                                <label
                                    for="desktop-pin"
                                    class="text-sm font-bold text-slate-800 dark:text-slate-100"
                                >
                                    Code PIN
                                </label>
                                <input
                                    id="desktop-pin"
                                    ref="pinInput"
                                    v-model="pin"
                                    name="pin"
                                    type="password"
                                    inputmode="numeric"
                                    pattern="[0-9]{4}"
                                    minlength="4"
                                    maxlength="4"
                                    autocomplete="new-password"
                                    required
                                    class="h-14 w-full min-w-0 rounded-2xl border border-slate-300 bg-white px-5 text-center font-mono text-2xl font-extrabold tracking-[0.55em] text-slate-950 transition outline-none focus:border-brand focus:ring-4 focus:ring-brand dark:border-slate-700 dark:bg-slate-950 dark:text-white dark:focus:ring-brand"
                                    aria-describedby="desktop-pin-help desktop-pin-error"
                                    :aria-invalid="Boolean(form.errors.pin)"
                                    data-test="desktop-pin-input"
                                />
                                <span
                                    id="desktop-pin-help"
                                    class="text-xs text-slate-500 dark:text-slate-400"
                                >
                                    Exactement 4 chiffres
                                </span>
                                <InputError
                                    id="desktop-pin-error"
                                    :message="form.errors.pin"
                                />
                            </div>

                            <div class="grid min-w-0 gap-2">
                                <label
                                    for="desktop-pin-confirmation"
                                    class="text-sm font-bold text-slate-800 dark:text-slate-100"
                                >
                                    Confirmer le PIN
                                </label>
                                <input
                                    id="desktop-pin-confirmation"
                                    v-model="pinConfirmation"
                                    name="pin_confirmation"
                                    type="password"
                                    inputmode="numeric"
                                    pattern="[0-9]{4}"
                                    minlength="4"
                                    maxlength="4"
                                    autocomplete="new-password"
                                    required
                                    class="h-14 w-full min-w-0 rounded-2xl border border-slate-300 bg-white px-5 text-center font-mono text-2xl font-extrabold tracking-[0.55em] text-slate-950 transition outline-none focus:border-brand focus:ring-4 focus:ring-brand dark:border-slate-700 dark:bg-slate-950 dark:text-white dark:focus:ring-brand"
                                    aria-describedby="desktop-pin-confirmation-error"
                                    :aria-invalid="
                                        Boolean(form.errors.pin_confirmation)
                                    "
                                    data-test="desktop-pin-confirmation"
                                />
                                <span
                                    class="text-xs text-slate-500 dark:text-slate-400"
                                >
                                    Saisissez les mêmes 4 chiffres
                                </span>
                                <InputError
                                    id="desktop-pin-confirmation-error"
                                    :message="form.errors.pin_confirmation"
                                />
                            </div>
                        </div>

                        <div class="grid gap-2">
                            <label
                                for="desktop-device-name"
                                class="text-sm font-bold text-slate-800 dark:text-slate-100"
                            >
                                Nom de cet appareil
                            </label>
                            <div class="relative">
                                <MonitorCheck
                                    class="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-slate-400"
                                    aria-hidden="true"
                                />
                                <input
                                    id="desktop-device-name"
                                    v-model="form.device_name"
                                    name="device_name"
                                    type="text"
                                    autocomplete="off"
                                    maxlength="100"
                                    required
                                    class="h-12 w-full rounded-xl border border-slate-300 bg-white pr-4 pl-12 text-sm font-semibold text-slate-900 transition outline-none focus:border-brand focus:ring-4 focus:ring-brand dark:border-slate-700 dark:bg-slate-950 dark:text-white dark:focus:ring-brand"
                                    :aria-invalid="
                                        Boolean(form.errors.device_name)
                                    "
                                    aria-describedby="desktop-device-name-error"
                                />
                            </div>
                            <InputError
                                id="desktop-device-name-error"
                                :message="form.errors.device_name"
                            />
                        </div>

                        <div
                            v-if="localError || form.errors.device_token"
                            class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm leading-5 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200"
                            role="alert"
                        >
                            {{ localError || form.errors.device_token }}
                        </div>

                        <button
                            type="submit"
                            class="inline-flex h-13 w-full items-center justify-center gap-2 rounded-2xl bg-brand px-5 text-sm font-extrabold text-white shadow-lg shadow-brand-deep/20 transition hover:bg-brand-deep focus-visible:ring-4 focus-visible:ring-brand focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="form.processing || Boolean(localError)"
                            data-test="desktop-pin-enroll-submit"
                        >
                            <LoaderCircle
                                v-if="form.processing"
                                class="size-5 animate-spin"
                                aria-hidden="true"
                            />
                            <LockKeyhole
                                v-else
                                class="size-5"
                                aria-hidden="true"
                            />
                            {{
                                form.processing
                                    ? 'Sécurisation en cours…'
                                    : 'Activer la connexion par PIN'
                            }}
                        </button>
                    </form>

                    <div
                        class="mt-5 flex items-start gap-3 rounded-2xl bg-emerald-50 px-4 py-3 text-xs leading-5 text-emerald-900 dark:bg-emerald-950/35 dark:text-emerald-100"
                    >
                        <ShieldCheck
                            class="mt-0.5 size-4 shrink-0 text-emerald-600"
                            aria-hidden="true"
                        />
                        <span>
                            Votre PIN n’est jamais enregistré dans ce
                            navigateur. Seul un identifiant cryptographique de
                            cet appareil est conservé après validation.
                        </span>
                    </div>
                </div>
            </section>
        </div>
    </Teleport>
</template>
