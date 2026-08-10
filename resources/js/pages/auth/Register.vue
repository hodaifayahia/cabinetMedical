<script setup lang="ts">
import type { Page } from '@inertiajs/core';
import { Form, Head } from '@inertiajs/vue3';
import {
    ArrowRight,
    Building2,
    KeyRound,
    LockKeyhole,
    MonitorCheck,
    Stethoscope,
    UserRound,
} from '@lucide/vue';
import { isTauri } from '@tauri-apps/api/core';
import { computed, onMounted, ref } from 'vue';
import AuthBackLink from '@/components/auth/AuthBackLink.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { markDesktopOnboardingComplete } from '@/lib/desktopOnboarding';
import {
    defaultDesktopDeviceName,
    generateDesktopDeviceToken,
    normalizeDesktopPin,
    saveDesktopPinEnrollment,
} from '@/lib/desktopPin';
import { login } from '@/routes';
import { store } from '@/routes/register';

defineProps<{
    passwordRules: string;
    specialtySuggestions: string[];
    wilayas: { code: number; name: string }[];
}>();

const desktopRuntime = ref(false);
const runtimeResolved = ref(false);
const deviceToken = ref('');
const deviceName = ref('Poste Drclick');
const pinValue = ref('');
const pinConfirmationValue = ref('');
const pinSetupError = ref('');

const pin = computed({
    get: () => pinValue.value,
    set: (value: string) => {
        pinValue.value = normalizeDesktopPin(value);
    },
});
const pinConfirmation = computed({
    get: () => pinConfirmationValue.value,
    set: (value: string) => {
        pinConfirmationValue.value = normalizeDesktopPin(value);
    },
});

function prepareDesktopPin(): void {
    deviceName.value = defaultDesktopDeviceName();

    try {
        deviceToken.value = generateDesktopDeviceToken();
        pinSetupError.value = '';
    } catch {
        pinSetupError.value =
            'La configuration sécurisée du PIN est indisponible. Redémarrez Drclick puis réessayez.';
    }
}

function handleRegistrationSuccess(page: Page): void {
    markDesktopOnboardingComplete();

    if (!desktopRuntime.value || !deviceToken.value) {
        return;
    }

    const user = (
        page.props as {
            auth?: { user?: { id: number; name: string } | null };
        }
    ).auth?.user;

    if (
        !user ||
        !saveDesktopPinEnrollment(
            deviceToken.value,
            deviceName.value,
            user.id,
            user.name,
        )
    ) {
        pinSetupError.value =
            'Le cabinet a été créé, mais cet appareil n’a pas pu mémoriser le PIN.';
    }
}

onMounted(() => {
    desktopRuntime.value = isTauri();

    if (desktopRuntime.value) {
        prepareDesktopPin();
    }

    runtimeResolved.value = true;
});

defineOptions({
    layout: {
        title: 'Créer votre espace cabinet',
        description:
            'Renseignez vos coordonnées professionnelles. Votre accès sera ouvert dès l’activation de votre licence.',
    },
});
</script>

<template>
    <Head title="Créer un cabinet" />

    <AuthBackLink :href="login()" label="Retour à la connexion" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
        @success="handleRegistrationSuccess"
    >
        <fieldset class="rounded-lg border border-border p-4 sm:p-5">
            <legend class="sr-only">Vos coordonnées professionnelles</legend>
            <div class="mb-5 flex items-center gap-3">
                <span
                    class="flex size-9 items-center justify-center rounded-xl bg-brand-soft text-brand dark:bg-brand-deep dark:text-brand-mint"
                >
                    <UserRound class="size-4.5" aria-hidden="true" />
                </span>
                <div>
                    <p
                        class="text-sm font-extrabold text-slate-900 dark:text-white"
                    >
                        Vos coordonnées
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Le médecin responsable du cabinet
                    </p>
                </div>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="name" class="font-semibold">Nom complet</Label>
                    <Input
                        id="name"
                        type="text"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="name"
                        name="name"
                        placeholder="Dr Nadia Benali"
                        class="h-11"
                        :aria-invalid="Boolean(errors.name)"
                        :aria-describedby="
                            errors.name ? 'name-error' : undefined
                        "
                    />
                    <InputError id="name-error" :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="phone" class="font-semibold">
                        Numéro de téléphone
                    </Label>
                    <Input
                        id="phone"
                        type="tel"
                        required
                        :tabindex="2"
                        autocomplete="tel"
                        inputmode="tel"
                        name="phone"
                        placeholder="0555 12 34 56"
                        class="h-11"
                        :aria-invalid="Boolean(errors.phone)"
                        :aria-describedby="
                            errors.phone ? 'phone-error' : undefined
                        "
                    />
                    <InputError id="phone-error" :message="errors.phone" />
                </div>

                <div class="grid gap-2 sm:col-span-2">
                    <Label for="email" class="font-semibold"
                        >Adresse e-mail</Label
                    >
                    <Input
                        id="email"
                        type="email"
                        required
                        :tabindex="3"
                        autocomplete="email"
                        inputmode="email"
                        name="email"
                        placeholder="docteur@cabinet.dz"
                        class="h-11"
                        :aria-invalid="Boolean(errors.email)"
                        :aria-describedby="
                            errors.email ? 'email-error' : undefined
                        "
                    />
                    <InputError id="email-error" :message="errors.email" />
                </div>
            </div>
        </fieldset>

        <fieldset class="rounded-lg border border-border p-4 sm:p-5">
            <legend class="sr-only">Informations du cabinet</legend>
            <div class="mb-5 flex items-center gap-3">
                <span
                    class="flex size-9 items-center justify-center rounded-xl bg-brand-soft text-brand dark:bg-brand-deep dark:text-brand-mint"
                >
                    <Building2 class="size-4.5" aria-hidden="true" />
                </span>
                <div>
                    <p
                        class="text-sm font-extrabold text-slate-900 dark:text-white"
                    >
                        Votre cabinet
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        L’identité affichée dans votre espace
                    </p>
                </div>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="cabinet_name" class="font-semibold">
                        Nom du cabinet
                    </Label>
                    <Input
                        id="cabinet_name"
                        type="text"
                        required
                        :tabindex="4"
                        autocomplete="organization"
                        name="cabinet_name"
                        placeholder="Cabinet médical Benali"
                        class="h-11"
                        :aria-invalid="Boolean(errors.cabinet_name)"
                        :aria-describedby="
                            errors.cabinet_name
                                ? 'cabinet-name-error'
                                : undefined
                        "
                    />
                    <InputError
                        id="cabinet-name-error"
                        :message="errors.cabinet_name"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="specialization" class="font-semibold">
                        Spécialité médicale
                    </Label>
                    <Input
                        id="specialization"
                        type="text"
                        required
                        :tabindex="5"
                        name="specialization"
                        list="medical-specialties"
                        autocomplete="organization-title"
                        placeholder="Médecine générale"
                        class="h-11"
                        :aria-invalid="Boolean(errors.specialization)"
                        :aria-describedby="
                            errors.specialization
                                ? 'specialization-error'
                                : undefined
                        "
                    />
                    <datalist id="medical-specialties">
                        <option
                            v-for="specialty in specialtySuggestions"
                            :key="specialty"
                            :value="specialty"
                        />
                    </datalist>
                    <InputError
                        id="specialization-error"
                        :message="errors.specialization"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="wilaya" class="font-semibold">Wilaya</Label>
                    <select
                        id="wilaya"
                        name="wilaya"
                        required
                        :tabindex="6"
                        autocomplete="address-level1"
                        class="h-11 w-full rounded-xl border border-input bg-white px-3 text-sm shadow-sm transition outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 dark:bg-slate-900"
                        :aria-invalid="Boolean(errors.wilaya)"
                        :aria-describedby="
                            errors.wilaya ? 'wilaya-error' : undefined
                        "
                    >
                        <option value="" disabled selected>
                            Sélectionnez une wilaya
                        </option>
                        <option
                            v-for="wilaya in wilayas"
                            :key="wilaya.code"
                            :value="wilaya.code"
                        >
                            {{ String(wilaya.code).padStart(2, '0') }} —
                            {{ wilaya.name }}
                        </option>
                    </select>
                    <InputError id="wilaya-error" :message="errors.wilaya" />
                </div>
            </div>
        </fieldset>

        <fieldset class="rounded-lg border border-border p-4 sm:p-5">
            <legend class="sr-only">Sécurisation du compte</legend>
            <div class="mb-5 flex items-center gap-3">
                <span
                    class="flex size-9 items-center justify-center rounded-xl bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300"
                >
                    <LockKeyhole class="size-4.5" aria-hidden="true" />
                </span>
                <div>
                    <p
                        class="text-sm font-extrabold text-slate-900 dark:text-white"
                    >
                        Sécurisez votre accès
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Choisissez un mot de passe unique
                    </p>
                </div>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="password" class="font-semibold"
                        >Mot de passe</Label
                    >
                    <PasswordInput
                        id="password"
                        required
                        :tabindex="7"
                        autocomplete="new-password"
                        name="password"
                        placeholder="8 caractères minimum"
                        :passwordrules="passwordRules"
                        class="h-11"
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

                <div class="grid gap-2">
                    <Label for="password_confirmation" class="font-semibold">
                        Confirmer le mot de passe
                    </Label>
                    <PasswordInput
                        id="password_confirmation"
                        required
                        :tabindex="8"
                        autocomplete="new-password"
                        name="password_confirmation"
                        placeholder="Saisissez-le à nouveau"
                        :passwordrules="passwordRules"
                        class="h-11"
                        :aria-invalid="Boolean(errors.password_confirmation)"
                        :aria-describedby="
                            errors.password_confirmation
                                ? 'password-confirmation-error'
                                : undefined
                        "
                    />
                    <InputError
                        id="password-confirmation-error"
                        :message="errors.password_confirmation"
                    />
                </div>
            </div>
        </fieldset>

        <fieldset
            v-if="desktopRuntime"
            class="grid gap-5 rounded-lg border border-border p-4 sm:p-5"
        >
            <legend class="sr-only">Code PIN de cet ordinateur</legend>

            <div class="flex items-start gap-3">
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted text-foreground"
                >
                    <KeyRound class="size-4" aria-hidden="true" />
                </span>
                <div>
                    <p class="text-sm font-semibold">Code PIN rapide</p>
                    <p class="mt-1 text-xs leading-5 text-muted-foreground">
                        Choisissez 4 chiffres pour ouvrir Drclick sur cet
                        ordinateur sans ressaisir votre mot de passe.
                    </p>
                </div>
            </div>

            <input type="hidden" name="device_token" :value="deviceToken" />
            <input type="hidden" name="device_name" :value="deviceName" />

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="pin">Code PIN</Label>
                    <Input
                        id="pin"
                        v-model="pin"
                        name="pin"
                        type="password"
                        inputmode="numeric"
                        pattern="[0-9]{4}"
                        minlength="4"
                        maxlength="4"
                        autocomplete="new-password"
                        required
                        :tabindex="9"
                        class="h-11 text-center font-mono text-lg tracking-[0.45em]"
                        :aria-invalid="Boolean(errors.pin)"
                        aria-describedby="pin-help pin-error"
                    />
                    <p id="pin-help" class="text-xs text-muted-foreground">
                        Exactement 4 chiffres
                    </p>
                    <InputError id="pin-error" :message="errors.pin" />
                </div>

                <div class="grid gap-2">
                    <Label for="pin_confirmation">Confirmer le PIN</Label>
                    <Input
                        id="pin_confirmation"
                        v-model="pinConfirmation"
                        name="pin_confirmation"
                        type="password"
                        inputmode="numeric"
                        pattern="[0-9]{4}"
                        minlength="4"
                        maxlength="4"
                        autocomplete="new-password"
                        required
                        :tabindex="10"
                        class="h-11 text-center font-mono text-lg tracking-[0.45em]"
                        :aria-invalid="Boolean(errors.pin_confirmation)"
                        aria-describedby="pin-confirmation-error"
                    />
                    <p class="text-xs text-muted-foreground">
                        Saisissez les mêmes 4 chiffres
                    </p>
                    <InputError
                        id="pin-confirmation-error"
                        :message="errors.pin_confirmation"
                    />
                </div>
            </div>

            <div
                class="flex items-center gap-2 rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground"
            >
                <MonitorCheck class="size-4 shrink-0" aria-hidden="true" />
                {{ deviceName }}
            </div>

            <p
                v-if="pinSetupError"
                class="text-sm text-destructive"
                role="alert"
            >
                {{ pinSetupError }}
            </p>
        </fieldset>

        <Button
            type="submit"
            size="lg"
            class="h-12 w-full bg-brand text-white shadow-lg shadow-brand-deep/15 hover:bg-brand-deep"
            :tabindex="desktopRuntime ? 11 : 9"
            :disabled="processing || Boolean(pinSetupError)"
            data-test="register-user-button"
        >
            <Spinner v-if="processing" />
            <Stethoscope v-else class="size-4" aria-hidden="true" />
            {{ processing ? 'Création en cours…' : 'Créer mon cabinet' }}
            <ArrowRight
                v-if="!processing"
                class="ml-auto size-4"
                aria-hidden="true"
            />
        </Button>
    </Form>

    <div
        v-if="runtimeResolved"
        class="mt-7 flex flex-col items-center justify-center gap-2 text-center text-sm text-slate-500 sm:flex-row dark:text-slate-400"
    >
        <span>Vous rejoignez un cabinet existant ?</span>
        <TextLink
            :href="desktopRuntime ? '/desktop/cabinet-login' : '/join'"
            class="font-bold text-brand"
            :tabindex="10"
        >
            {{ desktopRuntime ? 'Connecter ce poste' : 'Demander l’accès' }}
        </TextLink>
    </div>
</template>
