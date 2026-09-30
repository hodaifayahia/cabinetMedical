<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CircleCheck, Cloud, CloudOff, Unlink } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfigurationTabs from '@/components/configuration/ConfigurationTabs.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PageBackButton from '@/components/PageBackButton.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { StaffSeats } from '@/pages/staff/display';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Configuration', href: '/app/configuration' }],
    },
});

const props = defineProps<{
    available: boolean;
    link: {
        linked: boolean;
        linkedElsewhere: boolean;
        endpoint: string | null;
        accountEmail: string | null;
        cabinetName: string | null;
        linkedAt: string | null;
    };
    seats: StaffSeats | null;
}>();

const form = useForm({
    endpoint: props.link.endpoint ?? '',
    email: '',
    password: '',
});

const submit = () => {
    form.post('/app/configuration/online-service', {
        preserveScroll: true,
        onFinish: () => form.reset('password'),
    });
};

const unlinking = ref(false);

const unlink = () => {
    if (
        !window.confirm(
            'Délier ce poste du service en ligne ? Il ne recevra plus les sièges achetés, ne synchronisera plus les rendez-vous mobiles et ne pourra plus utiliser l’assistant IA.',
        )
    ) {
        return;
    }

    router.delete('/app/configuration/online-service', {
        preserveScroll: true,
        onStart: () => (unlinking.value = true),
        onFinish: () => (unlinking.value = false),
    });
};

const linkedAtLabel = computed(() =>
    props.link.linkedAt
        ? new Intl.DateTimeFormat('fr-DZ', {
              dateStyle: 'long',
              timeStyle: 'short',
          }).format(new Date(props.link.linkedAt))
        : null,
);

const benefits = [
    'les sièges achetés pour votre cabinet, pour ajouter des utilisateurs ;',
    'les rendez-vous pris dans l’application mobile, dans les deux sens ;',
    'l’assistant IA, avec les crédits de votre cabinet.',
];
</script>

<template>
    <Head title="Service en ligne" />

    <div class="med-page">
        <PageBackButton
            href="/app/configuration"
            label="Retour à la configuration du cabinet"
        />
        <ConfigurationTabs />

        <section class="med-panel p-6">
            <Heading
                title="Service en ligne"
                description="Ce poste garde les dossiers du cabinet sur cet ordinateur. Relié au service en ligne Drclick, il reçoit aussi :"
            />

            <ul
                class="mb-6 list-disc space-y-1 pl-5 text-sm text-muted-foreground"
            >
                <li v-for="benefit in benefits" :key="benefit">
                    {{ benefit }}
                </li>
            </ul>

            <p
                v-if="available"
                class="mb-6 max-w-2xl text-sm text-muted-foreground"
                data-testid="online-service-upload-notice"
            >
                Pour que l’application mobile ne propose pas un créneau déjà
                pris, chaque synchronisation envoie aussi au service en ligne
                les rendez-vous de ce poste : nom, date de naissance et
                coordonnées du patient, motif, prestation et notes d’accueil.
                Les consultations, ordonnances et documents restent sur cet
                ordinateur.
            </p>

            <div
                v-if="!available"
                class="flex gap-3 rounded-xl border border-border bg-muted/40 p-4 text-sm"
            >
                <Cloud class="mt-0.5 size-5 shrink-0 text-muted-foreground" />
                <p>
                    Vous utilisez déjà le service en ligne : il n’y a rien à
                    relier ici. Cette page sert aux postes où l’application
                    Drclick est installée.
                </p>
            </div>

            <div
                v-else-if="link.linkedElsewhere"
                class="flex max-w-2xl gap-3 rounded-xl border border-border bg-muted/40 p-4 text-sm"
            >
                <Cloud class="mt-0.5 size-5 shrink-0 text-muted-foreground" />
                <p>
                    Ce poste est relié au service en ligne pour un autre cabinet
                    de cet ordinateur. Ce lien ne sert qu’à ce cabinet : le
                    vôtre ne synchronise pas ses rendez-vous, ne reçoit pas de
                    sièges et n’utilise pas l’assistant IA à travers lui.
                </p>
            </div>

            <div v-else-if="link.linked" class="max-w-2xl space-y-6">
                <div
                    class="flex gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900"
                >
                    <CircleCheck class="mt-0.5 size-5 shrink-0" />
                    <p>
                        Ce poste est relié au service en ligne. Les sièges et
                        l’assistant IA se mettent à jour dès qu’il est connecté
                        à Internet.
                    </p>
                </div>

                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-muted-foreground">Adresse</dt>
                        <dd class="font-medium break-all">
                            {{ link.endpoint }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Compte en ligne</dt>
                        <dd class="font-medium break-all">
                            {{ link.accountEmail ?? '—' }}
                        </dd>
                    </div>
                    <div v-if="link.cabinetName">
                        <dt class="text-muted-foreground">Cabinet en ligne</dt>
                        <dd class="font-medium">{{ link.cabinetName }}</dd>
                    </div>
                    <div v-if="linkedAtLabel">
                        <dt class="text-muted-foreground">Relié le</dt>
                        <dd class="font-medium">{{ linkedAtLabel }}</dd>
                    </div>
                    <div v-if="seats">
                        <dt class="text-muted-foreground">Sièges</dt>
                        <dd class="font-medium">
                            <span class="tabular-nums"
                                >{{ seats.used }} / {{ seats.limit }}</span
                            >
                            utilisés ·
                            <Link
                                href="/app/staff"
                                class="text-brand underline-offset-4 hover:underline"
                                >Gérer les utilisateurs</Link
                            >
                        </dd>
                    </div>
                </dl>

                <Button variant="outline" :disabled="unlinking" @click="unlink">
                    <Unlink class="size-4" />
                    Délier ce poste
                </Button>
            </div>

            <form v-else class="max-w-2xl space-y-6" @submit.prevent="submit">
                <div
                    class="flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
                >
                    <CloudOff class="mt-0.5 size-5 shrink-0" />
                    <p>
                        Ce poste n’est pas encore relié. Connectez-vous avec le
                        compte Drclick en ligne de votre cabinet ; le mot de
                        passe sert une seule fois et n’est pas conservé sur ce
                        poste.
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="endpoint">Adresse du service en ligne</Label>
                    <Input
                        id="endpoint"
                        v-model="form.endpoint"
                        type="url"
                        inputmode="url"
                        placeholder="https://…"
                        autocomplete="off"
                        required
                    />
                    <InputError :message="form.errors.endpoint" />
                </div>

                <div class="grid gap-6 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="email">E-mail du compte en ligne</Label>
                        <Input
                            id="email"
                            v-model="form.email"
                            type="email"
                            autocomplete="off"
                            required
                        />
                        <InputError :message="form.errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password">Mot de passe</Label>
                        <Input
                            id="password"
                            v-model="form.password"
                            type="password"
                            autocomplete="off"
                            required
                        />
                        <InputError :message="form.errors.password" />
                    </div>
                </div>

                <div class="flex justify-end">
                    <Button type="submit" :disabled="form.processing">
                        <Cloud class="size-4" />
                        {{ form.processing ? 'Connexion…' : 'Relier ce poste' }}
                    </Button>
                </div>
            </form>
        </section>
    </div>
</template>
