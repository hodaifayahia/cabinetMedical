<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import {
    ArrowRight,
    Building2,
    Check,
    LockKeyhole,
    Stethoscope,
    UserRound,
} from '@lucide/vue';
import { isTauri } from '@tauri-apps/api/core';
import { onMounted, ref } from 'vue';
import AuthBackLink from '@/components/auth/AuthBackLink.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { markDesktopOnboardingComplete } from '@/lib/desktopOnboarding';
import { login } from '@/routes';
import { store } from '@/routes/register';

defineProps<{
    passwordRules: string;
    specialtySuggestions: string[];
    wilayas: { code: number; name: string }[];
}>();

const desktopRuntime = ref(false);
const runtimeResolved = ref(false);

onMounted(() => {
    desktopRuntime.value = isTauri();
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

    <div
        class="mb-7 flex flex-wrap items-center gap-x-5 gap-y-2 rounded-2xl border border-sky-100 bg-sky-50/70 px-4 py-3 text-xs font-semibold text-sky-900 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-100"
        aria-label="Garanties d’inscription"
    >
        <span class="inline-flex items-center gap-1.5">
            <Check class="size-3.5 text-emerald-600" aria-hidden="true" />
            Inscription sécurisée
        </span>
        <span class="inline-flex items-center gap-1.5">
            <Check class="size-3.5 text-emerald-600" aria-hidden="true" />
            Données confidentielles
        </span>
        <span class="inline-flex items-center gap-1.5">
            <Check class="size-3.5 text-emerald-600" aria-hidden="true" />
            Activation contrôlée
        </span>
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
        @success="markDesktopOnboardingComplete"
    >
        <fieldset
            class="rounded-2xl border border-slate-200 bg-slate-50/60 p-4 sm:p-5 dark:border-slate-700 dark:bg-slate-800/40"
        >
            <legend class="sr-only">Vos coordonnées professionnelles</legend>
            <div class="mb-5 flex items-center gap-3">
                <span
                    class="flex size-9 items-center justify-center rounded-xl bg-sky-100 text-[#1268a5] dark:bg-sky-950 dark:text-sky-300"
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

        <fieldset
            class="rounded-2xl border border-slate-200 bg-slate-50/60 p-4 sm:p-5 dark:border-slate-700 dark:bg-slate-800/40"
        >
            <legend class="sr-only">Informations du cabinet</legend>
            <div class="mb-5 flex items-center gap-3">
                <span
                    class="flex size-9 items-center justify-center rounded-xl bg-cyan-100 text-cyan-700 dark:bg-cyan-950 dark:text-cyan-300"
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

        <fieldset
            class="rounded-2xl border border-slate-200 bg-slate-50/60 p-4 sm:p-5 dark:border-slate-700 dark:bg-slate-800/40"
        >
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

        <Button
            type="submit"
            size="lg"
            class="h-12 w-full bg-[#1268a5] text-white shadow-lg shadow-sky-800/15 hover:bg-[#0d578b]"
            tabindex="9"
            :disabled="processing"
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
            class="font-bold text-[#1268a5]"
            :tabindex="10"
        >
            {{ desktopRuntime ? 'Connecter ce poste' : 'Demander l’accès' }}
        </TextLink>
    </div>
</template>
