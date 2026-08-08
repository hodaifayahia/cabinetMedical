<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import {
    index as confirmOptions,
    store as confirmStore,
} from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
import AuthBackLink from '@/components/auth/AuthBackLink.vue';
import InputError from '@/components/InputError.vue';
import PasskeyVerify from '@/components/PasskeyVerify.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import { store } from '@/routes/password/confirm';

defineOptions({
    layout: {
        title: 'Confirmer votre identité',
        description:
            'Cette zone protège les sauvegardes, les services cloud et la licence. Confirmez votre mot de passe pour continuer.',
    },
});
</script>

<template>
    <Head title="Confirmer votre identité" />

    <AuthBackLink :href="dashboard()" label="Retour au tableau de bord" />

    <PasskeyVerify
        :routes="{
            options: confirmOptions(),
            submit: confirmStore(),
        }"
        label="Confirmer avec une clé d’accès"
        loading-label="Confirmation…"
        separator="Ou confirmer avec le mot de passe"
    />

    <Form
        v-bind="store.form()"
        reset-on-success
        v-slot="{ errors, processing }"
    >
        <div class="space-y-6">
            <div class="grid gap-2">
                <Label htmlFor="password">Mot de passe</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="current-password"
                    autofocus
                />

                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center">
                <Button
                    class="w-full"
                    :disabled="processing"
                    data-test="confirm-password-button"
                >
                    <Spinner v-if="processing" />
                    Confirmer et continuer
                </Button>
            </div>
        </div>
    </Form>
</template>
