<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import AuthBackLink from '@/components/auth/AuthBackLink.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        title: 'Vérification de l’e-mail',
        description:
            'Vérifiez votre adresse en utilisant le lien qui vient de vous être envoyé.',
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head title="Vérification de l’e-mail" />

    <AuthBackLink
        :href="logout()"
        method="post"
        as="button"
        label="Retour à la connexion"
    />

    <div
        v-if="status === 'verification-link-sent'"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        Un nouveau lien de vérification a été envoyé à votre adresse e-mail.
    </div>

    <Form
        v-bind="send.form()"
        class="space-y-6 text-center"
        v-slot="{ processing }"
    >
        <Button :disabled="processing" variant="secondary">
            <Spinner v-if="processing" />
            Renvoyer l’e-mail de vérification
        </Button>

        <TextLink :href="logout()" as="button" class="mx-auto block text-sm">
            Se déconnecter
        </TextLink>
    </Form>
</template>
