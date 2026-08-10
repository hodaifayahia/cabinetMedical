<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import AuthBackLink from '@/components/auth/AuthBackLink.vue';
import TextLink from '@/components/TextLink.vue';
import { logout } from '@/routes';

defineProps<{
    cabinet: { name: string } | null;
}>();

defineOptions({
    layout: {
        title: 'Demande en attente d’approbation',
        description: 'Votre demande d’accès a bien été envoyée.',
    },
});
</script>

<template>
    <Head title="En attente d’approbation" />

    <AuthBackLink
        :href="logout()"
        method="post"
        as="button"
        label="Retour à la connexion"
    />

    <div class="space-y-6 text-center">
        <div
            class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-brand-soft text-brand"
        >
            <ShieldCheck class="size-7" />
        </div>

        <p class="text-base font-semibold text-foreground">
            Votre demande est en attente d'approbation
        </p>

        <p class="text-sm leading-6 text-muted-foreground">
            <template v-if="cabinet">
                Votre demande pour rejoindre le cabinet
                <span class="font-semibold text-foreground">{{
                    cabinet.name
                }}</span>
                a été envoyée.
            </template>
            Le propriétaire du cabinet doit approuver votre compte et vous
            attribuer un rôle avant que vous puissiez accéder à l'application.
        </p>

        <TextLink :href="logout()" as="button" class="mx-auto block text-sm">
            Se déconnecter
        </TextLink>
    </div>
</template>
