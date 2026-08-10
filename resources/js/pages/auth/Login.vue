<script setup lang="ts">
import { Form, Head, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    Building2,
    CheckCircle2,
    KeyRound,
    LoaderCircle,
    LogIn,
    MonitorCheck,
    ShieldCheck,
    UserPlus,
} from '@lucide/vue';
import { isTauri } from '@tauri-apps/api/core';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import AuthBackLink from '@/components/auth/AuthBackLink.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    hasCompletedDesktopOnboarding,
    listenForPlatformOnboardingLocation,
    markDesktopOnboardingComplete,
} from '@/lib/desktopOnboarding';
import {
    clearDesktopPinEnrollment,
    isValidDesktopPin,
    normalizeDesktopPin,
    readDesktopPinEnrollment,
} from '@/lib/desktopPin';
import type { DesktopPinEnrollment } from '@/lib/desktopPin';
import { home, register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Heureux de vous revoir',
        description:
            'Connectez-vous à votre espace Drclick pour retrouver votre cabinet.',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
}>();

const desktopRuntime = ref(false);
const runtimeResolved = ref(false);
const desktopOnboardingComplete = ref(false);
const pinEnrollment = ref<DesktopPinEnrollment | null>(null);
const pinInput = ref<HTMLInputElement | null>(null);
const pinForm = useForm({
    device_token: pinEnrollment.value?.deviceToken ?? '',
    pin: '',
});
const loginPin = computed({
    get: () => pinForm.pin,
    set: (value: string) => {
        pinForm.pin = normalizeDesktopPin(value);
        pinForm.clearErrors('pin');
    },
});
const showRegistrationOptions = computed(
    () => !desktopRuntime.value || !desktopOnboardingComplete.value,
);
let stopPlatformLocationListener: () => void = () => undefined;

function loginWithPin(): void {
    if (!pinEnrollment.value) {
        return;
    }

    if (!isValidDesktopPin(pinForm.pin)) {
        pinForm.setError('pin', 'Saisissez exactement 4 chiffres.');
        void nextTick(() => pinInput.value?.focus());

        return;
    }

    pinForm.device_token = pinEnrollment.value.deviceToken;
    pinForm.post('/desktop/pin/login', {
        preserveScroll: true,
        onSuccess: markDesktopOnboardingComplete,
        onError: async () => {
            pinForm.reset('pin');
            await nextTick();
            pinInput.value?.focus();
        },
    });
}

async function useAnotherAccount(): Promise<void> {
    clearDesktopPinEnrollment();
    pinEnrollment.value = null;
    pinForm.pin = '';
    pinForm.device_token = '';
    pinForm.clearErrors();
    await nextTick();
    document.getElementById('email')?.focus();
}

onMounted(async () => {
    desktopRuntime.value = isTauri();

    if (desktopRuntime.value) {
        desktopOnboardingComplete.value = hasCompletedDesktopOnboarding();
        pinEnrollment.value = readDesktopPinEnrollment();
        pinForm.device_token = pinEnrollment.value?.deviceToken ?? '';
    }

    stopPlatformLocationListener = listenForPlatformOnboardingLocation();
    runtimeResolved.value = true;

    if (pinEnrollment.value) {
        await nextTick();
        pinInput.value?.focus();
    }
});

onBeforeUnmount(() => {
    stopPlatformLocationListener();
});
</script>

<template>
    <Head title="Connexion" />

    <AuthBackLink :href="home()" label="Retour à l’accueil" />

    <div
        v-if="!runtimeResolved"
        class="flex min-h-80 items-center justify-center"
        role="status"
        aria-live="polite"
        aria-busy="true"
        data-test="desktop-runtime-pending"
    >
        <span
            class="inline-flex items-center gap-3 text-sm font-semibold text-slate-500 dark:text-slate-400"
        >
            <LoaderCircle class="size-5 animate-spin" aria-hidden="true" />
            Préparation de votre accès…
        </span>
    </div>

    <div
        v-if="runtimeResolved && status"
        class="mb-6 flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200"
        role="status"
    >
        <CheckCircle2
            class="mt-0.5 size-5 shrink-0 text-emerald-600"
            aria-hidden="true"
        />
        <span class="leading-6">{{ status }}</span>
    </div>

    <template v-if="runtimeResolved && pinEnrollment">
        <section
            class="rounded-3xl border border-brand/15 bg-brand-soft/45 p-5 shadow-inner shadow-brand-deep/5 dark:border-brand/25 dark:bg-brand-soft/20"
            aria-labelledby="desktop-pin-login-title"
            data-test="desktop-pin-login"
        >
            <div class="flex items-center gap-3">
                <span
                    class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-brand text-white shadow-lg shadow-brand-deep/20"
                >
                    <KeyRound class="size-6" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p
                        class="text-xs font-extrabold tracking-[0.14em] text-brand uppercase dark:text-brand-mint"
                    >
                        Accès rapide sécurisé
                    </p>
                    <h3
                        id="desktop-pin-login-title"
                        class="truncate text-lg font-extrabold text-slate-950 dark:text-white"
                    >
                        Bonjour {{ pinEnrollment.userName }}
                    </h3>
                </div>
            </div>

            <form class="mt-6" @submit.prevent="loginWithPin">
                <input
                    type="hidden"
                    name="device_token"
                    :value="pinForm.device_token"
                />

                <Label
                    for="desktop-login-pin"
                    class="font-semibold text-slate-700 dark:text-slate-200"
                >
                    Votre code PIN à 4 chiffres
                </Label>
                <input
                    id="desktop-login-pin"
                    ref="pinInput"
                    v-model="loginPin"
                    name="pin"
                    type="password"
                    inputmode="numeric"
                    pattern="[0-9]{4}"
                    minlength="4"
                    maxlength="4"
                    autocomplete="off"
                    required
                    class="mt-2 h-16 w-full rounded-2xl border border-slate-300 bg-white px-6 text-center font-mono text-3xl font-extrabold tracking-[0.7em] text-slate-950 transition outline-none placeholder:tracking-normal focus:border-brand focus:ring-4 focus:ring-brand dark:border-slate-700 dark:bg-slate-950 dark:text-white dark:focus:ring-brand"
                    aria-describedby="desktop-login-pin-help desktop-login-pin-error"
                    :aria-invalid="
                        Boolean(
                            pinForm.errors.pin || pinForm.errors.device_token,
                        )
                    "
                    data-test="desktop-pin-login-input"
                />
                <p
                    id="desktop-login-pin-help"
                    class="mt-2 text-center text-xs text-slate-500 dark:text-slate-400"
                >
                    Aucun e-mail ni mot de passe n’est nécessaire sur ce poste.
                </p>
                <InputError
                    id="desktop-login-pin-error"
                    class="mt-2 text-center"
                    :message="pinForm.errors.pin || pinForm.errors.device_token"
                />

                <Button
                    type="submit"
                    size="lg"
                    class="mt-5 h-12 w-full bg-brand text-white shadow-lg shadow-brand-deep/15 hover:bg-brand-deep"
                    :disabled="pinForm.processing"
                    data-test="desktop-pin-login-submit"
                >
                    <LoaderCircle
                        v-if="pinForm.processing"
                        class="size-5 animate-spin"
                        aria-hidden="true"
                    />
                    <LogIn v-else class="size-5" aria-hidden="true" />
                    {{
                        pinForm.processing
                            ? 'Ouverture en cours…'
                            : 'Ouvrir mon cabinet'
                    }}
                </Button>
            </form>

            <div
                class="mt-4 flex items-center justify-center gap-2 text-xs text-slate-500 dark:text-slate-400"
            >
                <MonitorCheck class="size-4" aria-hidden="true" />
                <span class="truncate">{{ pinEnrollment.deviceName }}</span>
                <ShieldCheck
                    class="size-4 text-emerald-600"
                    aria-label="Appareil vérifié"
                />
            </div>
        </section>

        <button
            type="button"
            class="mx-auto mt-5 block rounded-lg px-3 py-2 text-xs font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-slate-800 focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
            data-test="desktop-pin-use-another-account"
            @click="useAnotherAccount"
        >
            Utiliser un autre compte
        </button>
    </template>

    <template v-else-if="runtimeResolved">
        <Form
            v-bind="store.form()"
            :reset-on-success="['password']"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
            @success="markDesktopOnboardingComplete"
        >
            <div class="grid gap-5">
                <div class="grid gap-2">
                    <Label
                        for="email"
                        class="font-semibold text-slate-700 dark:text-slate-200"
                    >
                        Adresse e-mail
                    </Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="email"
                        inputmode="email"
                        placeholder="docteur@cabinet.dz"
                        class="h-12"
                        :aria-invalid="Boolean(errors.email)"
                        :aria-describedby="
                            errors.email ? 'email-error' : undefined
                        "
                    />
                    <InputError id="email-error" :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between gap-4">
                        <Label
                            for="password"
                            class="font-semibold text-slate-700 dark:text-slate-200"
                        >
                            Mot de passe
                        </Label>
                        <TextLink
                            v-if="canResetPassword"
                            :href="request()"
                            class="text-sm font-semibold text-brand no-underline hover:underline"
                            :tabindex="5"
                        >
                            Mot de passe oublié ?
                        </TextLink>
                    </div>
                    <PasswordInput
                        id="password"
                        name="password"
                        required
                        :tabindex="2"
                        autocomplete="current-password"
                        placeholder="Votre mot de passe"
                        class="h-12"
                        :aria-invalid="Boolean(errors.password)"
                        :aria-describedby="
                            errors.password ? 'password-error' : undefined
                        "
                    />
                    <InputError
                        id="password-error"
                        :message="errors.password"
                    />
                </div>

                <Label
                    for="remember"
                    class="flex w-fit cursor-pointer items-center gap-3 text-sm text-slate-600 dark:text-slate-300"
                >
                    <Checkbox id="remember" name="remember" :tabindex="3" />
                    <span>Rester connecté sur cet appareil</span>
                </Label>

                <Button
                    type="submit"
                    size="lg"
                    class="mt-1 h-12 w-full bg-brand text-white shadow-lg shadow-brand-deep/15 hover:bg-brand-deep"
                    :tabindex="4"
                    :disabled="processing"
                    data-test="login-button"
                >
                    <Spinner v-if="processing" />
                    <LogIn v-else class="size-4" aria-hidden="true" />
                    {{ processing ? 'Connexion en cours…' : 'Se connecter' }}
                    <ArrowRight
                        v-if="!processing"
                        class="ml-auto size-4"
                        aria-hidden="true"
                    />
                </Button>
            </div>
        </Form>
    </template>

    <div
        v-if="
            runtimeResolved &&
            canRegister &&
            showRegistrationOptions &&
            !pinEnrollment
        "
        class="mt-6 grid gap-3 sm:grid-cols-2"
    >
        <TextLink
            :href="register()"
            class="inline-flex h-11 items-center justify-center gap-2 rounded-md border border-input bg-background px-4 text-sm font-medium no-underline shadow-xs transition-colors hover:bg-accent hover:text-accent-foreground"
            :tabindex="6"
        >
            <Building2 class="size-4" aria-hidden="true" />
            Créer un cabinet
        </TextLink>

        <TextLink
            :href="desktopRuntime ? '/desktop/cabinet-login' : '/join'"
            class="inline-flex h-11 items-center justify-center gap-2 rounded-md border border-input bg-background px-4 text-sm font-medium no-underline shadow-xs transition-colors hover:bg-accent hover:text-accent-foreground"
            :tabindex="7"
        >
            <UserPlus class="size-4" aria-hidden="true" />
            {{ desktopRuntime ? 'Cabinet existant' : 'Rejoindre un cabinet' }}
        </TextLink>
    </div>
</template>
