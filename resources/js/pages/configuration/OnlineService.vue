<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    CircleCheck,
    Cloud,
    CloudOff,
    RefreshCw,
    TriangleAlert,
    Unlink,
    WifiOff,
} from '@lucide/vue';
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
    sync?: {
        automatic: boolean;
        lastSyncedAt: string | null;
        lastFailedAt: string | null;
        offline: boolean;
        error: string | null;
    } | null;
    canSyncNow?: boolean;
    ownerEmail?: string | null;
    aiMediaEnabled?: boolean;
}>();

// Images (ECG, scanned documents) and dictation audio for the AI assistant.
const aiMediaSaving = ref(false);

const setAiMedia = (enabled: boolean): void => {
    aiMediaSaving.value = true;
    router.put(
        '/app/configuration/online-service/ai',
        { ai_media_enabled: enabled },
        {
            preserveScroll: true,
            onFinish: () => {
                aiMediaSaving.value = false;
            },
        },
    );
};

const form = useForm({
    endpoint: props.link.endpoint ?? '',
    email: props.ownerEmail ?? '',
    password: '',
});

// The address is the one this build ships with; it only needs changing in
// rare cases, so it stays out of the way unless it is missing or refused.
const editingEndpoint = ref(!props.link.endpoint);
const showEndpoint = computed(
    () => editingEndpoint.value || Boolean(form.errors.endpoint),
);

const syncing = ref(false);

const syncNow = () => {
    router.post(
        '/app/appointments/sync-with-mobile',
        {},
        {
            preserveScroll: true,
            onStart: () => (syncing.value = true),
            onFinish: () => (syncing.value = false),
        },
    );
};

const formatDateTime = (value: string | null): string | null =>
    value
        ? new Intl.DateTimeFormat('fr-DZ', {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : null;

const lastSyncedLabel = computed(() =>
    formatDateTime(props.sync?.lastSyncedAt ?? null),
);

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
                        <strong data-testid="online-service-linked"
                            >Relié.</strong
                        >
                        Les rendez-vous de l’application mobile, les sièges et
                        l’assistant IA se mettent à jour dès que ce poste est
                        connecté à Internet. Sans Internet, le cabinet continue
                        de fonctionner normalement.
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

                <div
                    v-if="sync"
                    class="space-y-3 rounded-xl border border-border p-4 text-sm"
                    data-testid="online-service-sync-status"
                >
                    <p class="font-medium">
                        Rendez-vous de l’application mobile
                    </p>
                    <p class="text-muted-foreground">
                        {{
                            sync.automatic
                                ? 'Synchronisation automatique toutes les 2 minutes, dans les deux sens.'
                                : 'La synchronisation automatique n’est pas active sur ce poste pour le moment : utilisez « Synchroniser maintenant ».'
                        }}
                    </p>
                    <p>
                        Dernière synchronisation :
                        <span class="font-medium">{{
                            lastSyncedLabel ?? 'pas encore effectuée'
                        }}</span>
                    </p>
                    <p
                        v-if="sync.offline"
                        class="flex items-start gap-2 text-amber-800 dark:text-amber-300"
                        data-testid="online-service-sync-offline"
                    >
                        <WifiOff class="mt-0.5 size-4 shrink-0" />
                        Hors ligne — nouvelle tentative automatique. Le cabinet
                        fonctionne normalement en attendant.
                    </p>
                    <p
                        v-else-if="sync.error"
                        class="flex items-start gap-2 text-destructive"
                        data-testid="online-service-sync-error"
                    >
                        <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                        {{ sync.error }}
                    </p>
                    <Button
                        v-if="canSyncNow"
                        variant="outline"
                        size="sm"
                        :disabled="syncing"
                        @click="syncNow"
                    >
                        <RefreshCw
                            class="size-4"
                            :class="{ 'animate-spin': syncing }"
                        />
                        {{
                            syncing
                                ? 'Synchronisation…'
                                : 'Synchroniser maintenant'
                        }}
                    </Button>
                </div>

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
                        Ce poste n’est pas encore relié. Connectez-vous une
                        seule fois avec le compte Drclick en ligne du titulaire
                        du cabinet (même adresse e-mail que sur ce poste) ; le
                        mot de passe n’est pas conservé. Les rendez-vous mobiles
                        se synchronisent ensuite tout seuls.
                    </p>
                </div>

                <p v-if="!showEndpoint" class="text-sm text-muted-foreground">
                    Service en ligne :
                    <span class="font-medium text-foreground">{{
                        form.endpoint
                    }}</span>
                    ·
                    <button
                        type="button"
                        class="text-brand underline-offset-4 hover:underline"
                        @click="editingEndpoint = true"
                    >
                        Modifier l’adresse
                    </button>
                </p>

                <div v-else class="grid gap-2">
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

        <section class="med-panel p-6" data-test="ai-media-setting">
            <Heading
                variant="small"
                title="Assistant IA : ce qui quitte ce PC"
                description="Les dossiers restent sur ce PC. Pour répondre, l’assistant IA reçoit le contenu médical utile sans le nom, le téléphone ni l’adresse du patient."
            />
            <div
                class="mt-4 flex flex-wrap items-center justify-between gap-4 rounded-lg border border-border p-4"
            >
                <div class="min-w-0 text-sm">
                    <p class="font-semibold text-slate-900 dark:text-white">
                        Envoyer aussi les images et la voix
                    </p>
                    <p class="text-muted-foreground">
                        Lecture des ECG, analyse des documents scannés et dictée
                        vocale. Désactivé : ces fonctions sont indisponibles et
                        seul le texte est envoyé.
                    </p>
                </div>
                <Button
                    type="button"
                    :variant="
                        props.aiMediaEnabled === false ? 'default' : 'outline'
                    "
                    :disabled="aiMediaSaving"
                    @click="setAiMedia(props.aiMediaEnabled === false)"
                >
                    {{
                        props.aiMediaEnabled === false
                            ? 'Autoriser'
                            : 'Ne plus envoyer'
                    }}
                </Button>
            </div>
            <p class="mt-2 text-xs text-muted-foreground">
                État actuel :
                <strong>{{
                    props.aiMediaEnabled === false
                        ? 'texte seulement'
                        : 'texte, images et voix'
                }}</strong>
            </p>
        </section>
    </div>
</template>
