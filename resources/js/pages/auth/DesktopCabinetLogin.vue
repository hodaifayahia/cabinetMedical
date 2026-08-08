<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import {
    ArrowRight,
    Building2,
    CheckCircle2,
    KeyRound,
    LockKeyhole,
    Mail,
} from '@lucide/vue';
import AuthBackLink from '@/components/auth/AuthBackLink.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { markDesktopOnboardingComplete } from '@/lib/desktopOnboarding';
import { home, login } from '@/routes';

defineOptions({
    layout: {
        title: 'Cabinet déjà configuré',
        description:
            'Connectez ce poste avec le compte que le responsable du cabinet a préparé pour vous.',
    },
});
</script>

<template>
    <Head title="Connexion à un cabinet existant" />

    <AuthBackLink :href="home()" label="Retour au choix initial" />

    <div
        class="mb-7 flex gap-3 rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950 dark:border-sky-900 dark:bg-sky-950/35 dark:text-sky-100"
        role="note"
    >
        <CheckCircle2
            class="mt-0.5 size-5 shrink-0 text-emerald-600"
            aria-hidden="true"
        />
        <p class="leading-6">
            Aucun nouveau compte ne sera créé. Utilisez l’e-mail et le mot de
            passe transmis par l’administrateur de votre cabinet.
        </p>
    </div>

    <Form
        action="/desktop/cabinet-login"
        method="post"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
        @success="markDesktopOnboardingComplete"
    >
        <fieldset
            class="rounded-2xl border border-slate-200 bg-slate-50/60 p-4 sm:p-5 dark:border-slate-700 dark:bg-slate-800/40"
        >
            <legend class="sr-only">Identification du cabinet</legend>
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
                        L’adresse du propriétaire identifie la bonne équipe
                    </p>
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="owner_email" class="font-semibold">
                    E-mail du propriétaire du cabinet
                </Label>
                <div class="relative">
                    <Mail
                        class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate-400"
                        aria-hidden="true"
                    />
                    <Input
                        id="owner_email"
                        type="email"
                        name="owner_email"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="email"
                        inputmode="email"
                        placeholder="proprietaire@cabinet.dz"
                        class="h-12 pl-10"
                        :aria-invalid="Boolean(errors.owner_email)"
                        :aria-describedby="
                            errors.owner_email ? 'owner-email-error' : undefined
                        "
                    />
                </div>
                <InputError
                    id="owner-email-error"
                    :message="errors.owner_email"
                />
            </div>
        </fieldset>

        <fieldset
            class="rounded-2xl border border-slate-200 bg-slate-50/60 p-4 sm:p-5 dark:border-slate-700 dark:bg-slate-800/40"
        >
            <legend class="sr-only">Vos identifiants personnels</legend>
            <div class="mb-5 flex items-center gap-3">
                <span
                    class="flex size-9 items-center justify-center rounded-xl bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300"
                >
                    <KeyRound class="size-4.5" aria-hidden="true" />
                </span>
                <div>
                    <p
                        class="text-sm font-extrabold text-slate-900 dark:text-white"
                    >
                        Votre compte collaborateur
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Chaque membre utilise ses propres identifiants
                    </p>
                </div>
            </div>

            <div class="grid gap-5">
                <div class="grid gap-2">
                    <Label for="email" class="font-semibold">
                        Votre adresse e-mail
                    </Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        required
                        :tabindex="2"
                        autocomplete="username"
                        inputmode="email"
                        placeholder="collaborateur@cabinet.dz"
                        class="h-12"
                        :aria-invalid="Boolean(errors.email)"
                        :aria-describedby="
                            errors.email ? 'email-error' : undefined
                        "
                    />
                    <InputError id="email-error" :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="password" class="font-semibold">
                        Mot de passe remis par l’administrateur
                    </Label>
                    <PasswordInput
                        id="password"
                        name="password"
                        required
                        :tabindex="3"
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
            </div>
        </fieldset>

        <Label
            for="remember"
            class="flex w-fit cursor-pointer items-center gap-3 text-sm text-slate-600 dark:text-slate-300"
        >
            <Checkbox id="remember" name="remember" value="1" :tabindex="4" />
            <span>Rester connecté sur ce poste</span>
        </Label>

        <Button
            type="submit"
            size="lg"
            class="h-12 w-full bg-[#1268a5] text-white shadow-lg shadow-sky-800/15 hover:bg-[#0d578b]"
            :tabindex="5"
            :disabled="processing"
            data-test="desktop-cabinet-login-button"
        >
            <Spinner v-if="processing" />
            <LockKeyhole v-else class="size-4" aria-hidden="true" />
            {{ processing ? 'Connexion en cours…' : 'Ouvrir ce cabinet' }}
            <ArrowRight
                v-if="!processing"
                class="ml-auto size-4"
                aria-hidden="true"
            />
        </Button>
    </Form>

    <div
        class="mt-7 grid gap-2 border-t border-slate-200 pt-6 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400"
    >
        <p>
            Votre responsable ne vous a pas encore créé de compte ? Demandez-lui
            de créer vos identifiants depuis la gestion du personnel.
        </p>
        <p>
            Vous êtes le propriétaire ?
            <TextLink :href="login()" class="font-bold text-[#1268a5]">
                Connexion classique
            </TextLink>
        </p>
    </div>
</template>
