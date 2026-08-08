<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import AuthBackLink from '@/components/auth/AuthBackLink.vue';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { email } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Mot de passe oublié',
        description: 'Recevez un lien de réinitialisation par e-mail',
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head title="Mot de passe oublié" />

    <AuthBackLink :href="login()" label="Retour à la connexion" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        {{ status }}
    </div>

    <div class="space-y-6">
        <Form v-bind="email.form()" v-slot="{ errors, processing }">
            <div class="grid gap-2">
                <Label for="email">Adresse e-mail</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="off"
                    autofocus
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="my-6 flex items-center justify-start">
                <Button
                    class="w-full"
                    :disabled="processing"
                    data-test="email-password-reset-link-button"
                >
                    <Spinner v-if="processing" />
                    Envoyer le lien de réinitialisation
                </Button>
            </div>
        </Form>

        <div class="space-x-1 text-center text-sm text-muted-foreground">
            <span>Ou revenir à la</span>
            <TextLink :href="login()">connexion</TextLink>
        </div>
    </div>
</template>
