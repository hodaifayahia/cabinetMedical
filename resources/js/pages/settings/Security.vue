<script setup lang="ts">
import { Form, Head, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import type { Props as ManagePasskeysProps } from '@/components/ManagePasskeys.vue';
import ManagePasskeys from '@/components/ManagePasskeys.vue';
import type { Props as ManageTwoFactorProps } from '@/components/ManageTwoFactor.vue';
import ManageTwoFactor from '@/components/ManageTwoFactor.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { edit } from '@/routes/security';

type Props = {
    passwordRules: string;
    localPinConfigured: boolean;
    idleLock: {
        minutes: number;
        minimum: number;
        maximum: number;
        canManage: boolean;
    };
} & ManagePasskeysProps &
    ManageTwoFactorProps;

const props = defineProps<Props>();
const pinEditorOpen = ref(!props.localPinConfigured);
const removePinConfirmationOpen = ref(false);
const pinForm = useForm({
    pin: '',
    pin_confirmation: '',
});
const removePinForm = useForm({});
const idleLockForm = useForm<{
    idle_lock_minutes: number | string;
}>({
    idle_lock_minutes: props.idleLock.minutes,
});

const submitPin = () => {
    pinForm.post('/settings/local-pin', {
        preserveScroll: true,
        onSuccess: () => {
            pinEditorOpen.value = false;
        },
        onFinish: () => pinForm.reset('pin', 'pin_confirmation'),
    });
};

const removePin = () => {
    removePinForm.delete('/settings/local-pin', {
        preserveScroll: true,
        onSuccess: () => {
            removePinConfirmationOpen.value = false;
            pinEditorOpen.value = true;
        },
    });
};

const updateIdleLock = () => {
    idleLockForm.put('/settings/idle-lock', {
        preserveScroll: true,
    });
};

watch(
    () => props.idleLock.minutes,
    (minutes) => {
        idleLockForm.idle_lock_minutes = minutes;
    },
);

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Paramètres de sécurité',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Paramètres de sécurité" />

    <h1 class="sr-only">Paramètres de sécurité</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Modifier le mot de passe"
            description="Utilisez un mot de passe long et unique pour protéger votre compte."
        />

        <Form
            v-bind="SecurityController.update.form()"
            :options="{
                preserveScroll: true,
            }"
            reset-on-success
            :reset-on-error="[
                'password',
                'password_confirmation',
                'current_password',
            ]"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="current_password">Mot de passe actuel</Label>
                <PasswordInput
                    id="current_password"
                    name="current_password"
                    class="mt-1 block w-full"
                    autocomplete="current-password"
                    placeholder="Mot de passe actuel"
                />
                <InputError :message="errors.current_password" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Nouveau mot de passe</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    placeholder="Nouveau mot de passe"
                    :passwordrules="props.passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">
                    Confirmer le mot de passe
                </Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    placeholder="Confirmer le mot de passe"
                    :passwordrules="props.passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <div class="flex items-center gap-4">
                <Button
                    :disabled="processing"
                    data-test="update-password-button"
                >
                    Enregistrer
                </Button>
            </div>
        </Form>
    </div>

    <section class="space-y-6" aria-labelledby="local-pin-heading">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                id="local-pin-heading"
                variant="small"
                title="Code PIN de déverrouillage local"
                description="Accès rapide à une session déjà authentifiée sur cet appareil. Le PIN ne remplace jamais le mot de passe, la double authentification ou une clé d’accès."
            />
            <Badge :variant="localPinConfigured ? 'default' : 'outline'">
                {{ localPinConfigured ? 'Configuré' : 'Non configuré' }}
            </Badge>
        </div>

        <div
            class="rounded-2xl border border-brand bg-brand-soft/70 p-4 text-sm leading-6 text-brand dark:border-brand dark:bg-brand-deep/40 dark:text-brand-soft"
        >
            Cette page et chaque ajout, modification ou suppression du PIN sont
            protégés par une confirmation récente du mot de passe.
        </div>

        <div
            v-if="localPinConfigured && !pinEditorOpen"
            class="flex flex-wrap gap-3"
        >
            <Button
                type="button"
                variant="outline"
                @click="pinEditorOpen = true"
            >
                Modifier le PIN
            </Button>
            <Button
                type="button"
                variant="destructive"
                @click="removePinConfirmationOpen = true"
            >
                Supprimer le PIN
            </Button>
        </div>

        <div
            v-if="removePinConfirmationOpen"
            class="space-y-4 rounded-2xl border border-red-200 bg-red-50 p-4 dark:border-red-900 dark:bg-red-950/30"
            role="alert"
        >
            <p class="text-sm leading-6 text-red-900 dark:text-red-100">
                Confirmez la suppression. Le mot de passe du compte restera
                disponible pour déverrouiller la session.
            </p>
            <div class="flex flex-wrap gap-3">
                <Button
                    type="button"
                    variant="destructive"
                    :disabled="removePinForm.processing"
                    data-test="remove-local-pin-confirm"
                    @click="removePin"
                >
                    <Spinner v-if="removePinForm.processing" />
                    Confirmer la suppression
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="removePinForm.processing"
                    @click="removePinConfirmationOpen = false"
                >
                    Annuler
                </Button>
            </div>
        </div>

        <form
            v-if="pinEditorOpen"
            class="space-y-5"
            novalidate
            @submit.prevent="submitPin"
        >
            <div class="grid gap-2">
                <Label for="local_pin">
                    {{ localPinConfigured ? 'Nouveau PIN' : 'PIN' }}
                </Label>
                <Input
                    id="local_pin"
                    v-model="pinForm.pin"
                    type="password"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    minlength="6"
                    maxlength="12"
                    autocomplete="off"
                    required
                    :aria-invalid="Boolean(pinForm.errors.pin)"
                    aria-describedby="local-pin-help"
                    data-test="local-pin"
                />
                <p id="local-pin-help" class="text-xs text-muted-foreground">
                    Entre 6 et 12 chiffres ASCII.
                </p>
                <InputError :message="pinForm.errors.pin" />
            </div>

            <div class="grid gap-2">
                <Label for="local_pin_confirmation">Confirmer le PIN</Label>
                <Input
                    id="local_pin_confirmation"
                    v-model="pinForm.pin_confirmation"
                    type="password"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    minlength="6"
                    maxlength="12"
                    autocomplete="off"
                    required
                    :aria-invalid="Boolean(pinForm.errors.pin_confirmation)"
                    data-test="local-pin-confirmation"
                />
                <InputError :message="pinForm.errors.pin_confirmation" />
            </div>

            <div class="flex flex-wrap gap-3">
                <Button
                    type="submit"
                    :disabled="pinForm.processing"
                    data-test="save-local-pin"
                >
                    <Spinner v-if="pinForm.processing" />
                    {{
                        localPinConfigured
                            ? 'Modifier le PIN'
                            : 'Configurer le PIN'
                    }}
                </Button>
                <Button
                    v-if="localPinConfigured"
                    type="button"
                    variant="outline"
                    :disabled="pinForm.processing"
                    @click="pinEditorOpen = false"
                >
                    Annuler
                </Button>
            </div>
        </form>
    </section>

    <section class="space-y-6" aria-labelledby="idle-lock-heading">
        <Heading
            id="idle-lock-heading"
            variant="small"
            title="Verrouillage après inactivité"
            description="Seule une activité réelle au clavier, à la souris ou au toucher remet le compteur à zéro. Les actualisations automatiques en arrière-plan ne le prolongent pas."
        />

        <form
            v-if="idleLock.canManage"
            class="space-y-5"
            @submit.prevent="updateIdleLock"
        >
            <div class="grid gap-2">
                <Label for="idle_lock_minutes">Délai en minutes</Label>
                <Input
                    id="idle_lock_minutes"
                    v-model="idleLockForm.idle_lock_minutes"
                    type="number"
                    inputmode="numeric"
                    :min="idleLock.minimum"
                    :max="idleLock.maximum"
                    required
                    :aria-invalid="
                        Boolean(idleLockForm.errors.idle_lock_minutes)
                    "
                    data-test="idle-lock-minutes"
                />
                <p class="text-xs text-muted-foreground">
                    Valeur autorisée : {{ idleLock.minimum }} à
                    {{ idleLock.maximum }} minutes.
                </p>
                <InputError :message="idleLockForm.errors.idle_lock_minutes" />
            </div>
            <Button
                type="submit"
                :disabled="idleLockForm.processing"
                data-test="save-idle-lock"
            >
                <Spinner v-if="idleLockForm.processing" />
                Enregistrer le délai
            </Button>
        </form>

        <div
            v-else
            class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm leading-6 text-slate-700 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200"
        >
            Verrouillage actif après
            <strong>{{ idleLock.minutes }} minutes</strong>. Seul un
            administrateur autorisé peut modifier ce délai d’installation.
        </div>
    </section>

    <ManageTwoFactor
        :canManageTwoFactor="canManageTwoFactor"
        :requiresConfirmation="requiresConfirmation"
        :twoFactorEnabled="twoFactorEnabled"
    />

    <ManagePasskeys
        :canManagePasskeys="canManagePasskeys"
        :passkeys="passkeys"
    />
</template>
