<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import {
    ArrowRight,
    Building2,
    Info,
    LockKeyhole,
    UserRound,
    UsersRound,
} from '@lucide/vue';
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

defineOptions({
    layout: {
        title: 'Rejoindre un cabinet',
        description:
            'Créez votre accès collaborateur. Le responsable du cabinet devra ensuite approuver votre demande.',
    },
});
</script>

<template>
    <Head title="Rejoindre un cabinet" />

    <AuthBackLink :href="login()" label="Retour à la connexion" />

    <div
        class="mb-7 flex gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/35 dark:text-amber-100"
        role="note"
    >
        <Info
            class="mt-0.5 size-5 shrink-0 text-amber-600"
            aria-hidden="true"
        />
        <p class="leading-6">
            Demandez au propriétaire l’adresse e-mail utilisée lors de la
            création du cabinet. Chaque cabinet peut accueillir jusqu’à trois
            utilisateurs.
        </p>
    </div>

    <Form
        action="/join"
        method="post"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
        @success="markDesktopOnboardingComplete"
    >
        <fieldset class="rounded-lg border border-border p-4 sm:p-5">
            <legend class="sr-only">Vos informations</legend>
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
                        Votre profil
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Les informations visibles par votre responsable
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
                        placeholder="Dr Karim Haddad"
                        class="h-11"
                        :aria-invalid="Boolean(errors.name)"
                        :aria-describedby="
                            errors.name ? 'name-error' : undefined
                        "
                    />
                    <InputError id="name-error" :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="email" class="font-semibold"
                        >Votre e-mail</Label
                    >
                    <Input
                        id="email"
                        type="email"
                        required
                        :tabindex="2"
                        autocomplete="email"
                        inputmode="email"
                        name="email"
                        placeholder="vous@cabinet.dz"
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
            <legend class="sr-only">Cabinet à rejoindre</legend>
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
                        Cabinet à rejoindre
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Identifiez-le grâce au compte propriétaire
                    </p>
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="owner_email" class="font-semibold">
                    E-mail du propriétaire du cabinet
                </Label>
                <Input
                    id="owner_email"
                    type="email"
                    required
                    :tabindex="3"
                    inputmode="email"
                    name="owner_email"
                    placeholder="proprietaire@cabinet.dz"
                    class="h-11"
                    :aria-invalid="Boolean(errors.owner_email)"
                    :aria-describedby="
                        errors.owner_email
                            ? 'owner-email-help owner-email-error'
                            : 'owner-email-help'
                    "
                />
                <p
                    id="owner-email-help"
                    class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400"
                >
                    <UsersRound class="size-3.5" aria-hidden="true" />
                    Votre demande sera envoyée à cette personne.
                </p>
                <InputError
                    id="owner-email-error"
                    :message="errors.owner_email"
                />
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
                        Votre mot de passe
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Il protège votre accès personnel
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
                        :tabindex="4"
                        autocomplete="new-password"
                        name="password"
                        placeholder="8 caractères minimum"
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
                        :tabindex="5"
                        autocomplete="new-password"
                        name="password_confirmation"
                        placeholder="Saisissez-le à nouveau"
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
            class="h-12 w-full bg-brand text-white shadow-lg shadow-brand-deep/15 hover:bg-brand-deep"
            tabindex="6"
            :disabled="processing"
            data-test="join-cabinet-button"
        >
            <Spinner v-if="processing" />
            <UsersRound v-else class="size-4" aria-hidden="true" />
            {{ processing ? 'Envoi en cours…' : 'Envoyer ma demande' }}
            <ArrowRight
                v-if="!processing"
                class="ml-auto size-4"
                aria-hidden="true"
            />
        </Button>
    </Form>

    <p class="mt-7 text-center text-sm text-slate-500 dark:text-slate-400">
        Vous avez déjà un compte ?
        <TextLink :href="login()" class="font-bold text-brand" :tabindex="7">
            Se connecter
        </TextLink>
    </p>
</template>
