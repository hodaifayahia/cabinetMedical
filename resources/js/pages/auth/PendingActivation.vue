<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Clock3 } from '@lucide/vue';
import TextLink from '@/components/TextLink.vue';
import { logout } from '@/routes';

defineProps<{
    cabinet: { name: string; status: string } | null;
}>();

defineOptions({
    layout: {
        title: 'Cabinet en attente d’activation',
        description: 'Votre inscription a bien été enregistrée.',
    },
});
</script>

<template>
    <Head title="En attente d’activation" />

    <div class="space-y-6 text-center">
        <div
            class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-brand-soft text-brand"
        >
            <Clock3 class="size-7" />
        </div>

        <p class="text-base font-semibold text-foreground">
            Votre cabinet est en attente d'activation
        </p>

        <p class="text-sm leading-6 text-muted-foreground">
            <template v-if="cabinet">
                Le cabinet
                <span class="font-semibold text-foreground">{{
                    cabinet.name
                }}</span>
                a bien été enregistré.
            </template>
            Notre équipe examine votre demande. Vous recevrez un e-mail dès que
            votre cabinet sera activé, puis vous pourrez vous connecter et
            commencer à l'utiliser.
        </p>

        <TextLink :href="logout()" as="button" class="mx-auto block text-sm">
            Se déconnecter
        </TextLink>
    </div>
</template>
