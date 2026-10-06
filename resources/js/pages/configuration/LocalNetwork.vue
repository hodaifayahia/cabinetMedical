<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    CircleCheck,
    Copy,
    LoaderCircle,
    MonitorSmartphone,
    Network,
    RotateCcw,
    ShieldCheck,
    TriangleAlert,
    Users,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import ConfigurationTabs from '@/components/configuration/ConfigurationTabs.vue';
import Heading from '@/components/Heading.vue';
import PageBackButton from '@/components/PageBackButton.vue';
import { Button } from '@/components/ui/button';
import type { LanHostStatus, RuntimeModeStatus } from '@/lib/lanNetwork';
import {
    isDesktopShell,
    lanHostLabel,
    lanHostStatus,
    openLanFirewall,
    readableNativeError,
    returnToLocalMode,
    runtimeModeStatus,
    setLanHost,
} from '@/lib/lanNetwork';
import type { StaffSeats } from '@/pages/staff/display';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Configuration', href: '/app/configuration' }],
    },
});

const props = defineProps<{
    desktopSupervised: boolean;
    lanClient: boolean;
    seats: StaffSeats | null;
    staffUrl: string;
}>();

const shell = ref(false);
const runtime = ref<RuntimeModeStatus | null>(null);
const status = ref<LanHostStatus | null>(null);
const loading = ref(true);
const switching = ref(false);
const firewallBusy = ref(false);
const firewallDone = ref(false);
const error = ref('');
const copied = ref<string | null>(null);
const restarting = ref(false);

const attached = computed(
    () => props.lanClient || runtime.value?.mode === 'attach',
);
const primaryUrl = computed(() => status.value?.addresses[0]?.url ?? null);
const otherUrls = computed(() => {
    const urls = (status.value?.addresses ?? [])
        .slice(1)
        .map((address) => address.url);

    if (status.value?.name_url) {
        urls.push(status.value.name_url);
    }

    return urls;
});

async function refresh(): Promise<void> {
    try {
        status.value = await lanHostStatus();
    } catch (failure) {
        status.value = null;
        error.value = readableNativeError(
            failure,
            'Impossible de lire l’état du réseau local.',
        );
    }
}

onMounted(async () => {
    shell.value = isDesktopShell();

    if (shell.value) {
        runtime.value = await runtimeModeStatus();

        if (!props.lanClient) {
            await refresh();
        }
    }

    loading.value = false;
});

async function toggle(): Promise<void> {
    if (!status.value) {
        return;
    }

    const enable = !status.value.enabled;

    if (
        !enable &&
        !window.confirm(
            'Arrêter le partage ? Les autres PC du cabinet ne pourront plus travailler jusqu’à sa réactivation.',
        )
    ) {
        return;
    }

    error.value = '';
    switching.value = true;

    try {
        status.value = await setLanHost(enable, status.value.port);
    } catch (failure) {
        error.value = readableNativeError(
            failure,
            'Le partage réseau n’a pas pu être modifié.',
        );
        await refresh();
    } finally {
        switching.value = false;
    }
}

async function allowFirewall(): Promise<void> {
    error.value = '';
    firewallBusy.value = true;

    try {
        firewallDone.value = await openLanFirewall();
    } catch (failure) {
        error.value = readableNativeError(failure);
    } finally {
        firewallBusy.value = false;
    }
}

async function copy(url: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(url);
        copied.value = url;
        window.setTimeout(() => {
            if (copied.value === url) {
                copied.value = null;
            }
        }, 2000);
    } catch {
        copied.value = null;
    }
}

async function backToLocal(): Promise<void> {
    if (
        !window.confirm(
            'Revenir au mode autonome ? Ce PC utilisera de nouveau sa propre base de données, distincte de celle du poste principal. Drclick va redémarrer.',
        )
    ) {
        return;
    }

    error.value = '';

    try {
        await returnToLocalMode();
        restarting.value = true;
    } catch (failure) {
        error.value = readableNativeError(failure);
    }
}
</script>

<template>
    <Head title="Réseau local" />

    <div class="med-page">
        <PageBackButton
            href="/app/configuration"
            label="Retour à la configuration du cabinet"
        />
        <ConfigurationTabs />

        <section class="med-panel space-y-6 p-6">
            <Heading
                title="Réseau local (plusieurs postes)"
                description="Travaillez à plusieurs PC du cabinet sur les mêmes dossiers, sans Internet : le PC du médecin (poste principal) garde la base de données, les autres PC (postes secondaires) s’y connectent par le réseau du cabinet."
            />

            <div
                v-if="!desktopSupervised"
                class="flex max-w-2xl gap-3 rounded-xl border border-border bg-muted/40 p-4 text-sm"
                data-test="local-network-web"
            >
                <Network class="mt-0.5 size-5 shrink-0 text-muted-foreground" />
                <p>
                    Ce réglage concerne l’application Drclick installée sur les
                    PC du cabinet. Avec le service en ligne, tous les
                    utilisateurs du cabinet partagent déjà les mêmes données.
                </p>
            </div>

            <div
                v-else-if="attached"
                class="max-w-2xl space-y-4"
                data-test="local-network-client"
            >
                <div
                    class="flex gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100"
                >
                    <CircleCheck class="mt-0.5 size-5 shrink-0" />
                    <p>
                        <strong>Poste secondaire.</strong> Ce PC est connecté au
                        poste principal
                        <span class="font-mono">{{
                            lanHostLabel(runtime?.url) || 'du cabinet'
                        }}</span>
                        : les dossiers sont enregistrés sur le PC du médecin. Le
                        partage se règle sur ce PC-là.
                    </p>
                </div>
                <p v-if="restarting" class="text-sm font-medium text-brand">
                    Drclick redémarre…
                </p>
                <Button
                    v-else-if="shell"
                    type="button"
                    variant="outline"
                    data-test="local-network-back-to-local"
                    @click="backToLocal"
                >
                    <RotateCcw class="size-4" aria-hidden="true" />
                    Revenir au mode autonome
                </Button>
            </div>

            <div
                v-else-if="!shell"
                class="flex max-w-2xl gap-3 rounded-xl border border-border bg-muted/40 p-4 text-sm"
            >
                <Network class="mt-0.5 size-5 shrink-0 text-muted-foreground" />
                <p>
                    Ouvrez cette page dans la fenêtre de l’application Drclick
                    du PC du médecin pour activer le partage.
                </p>
            </div>

            <div
                v-else
                class="max-w-3xl space-y-6"
                data-test="local-network-host"
            >
                <div
                    v-if="loading"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <LoaderCircle class="size-4 animate-spin" />
                    Lecture de l’état du réseau…
                </div>

                <template v-else-if="status">
                    <div
                        v-if="!status.available"
                        class="flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
                    >
                        <TriangleAlert class="mt-0.5 size-5 shrink-0" />
                        <p>
                            Seul le PC qui garde les données du cabinet (mode
                            autonome) peut les partager.
                        </p>
                    </div>

                    <div
                        v-else
                        class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-border p-4"
                    >
                        <div class="flex items-start gap-3">
                            <MonitorSmartphone
                                class="mt-0.5 size-5 shrink-0 text-brand"
                            />
                            <div class="space-y-1 text-sm">
                                <p class="font-semibold">
                                    Ce PC est le poste principal
                                </p>
                                <p
                                    class="text-muted-foreground"
                                    data-test="local-network-state"
                                >
                                    <template v-if="status.running">
                                        Partage actif sur le port
                                        {{ status.port }}.
                                    </template>
                                    <template v-else-if="status.starting">
                                        Démarrage du partage…
                                    </template>
                                    <template v-else>
                                        Les autres PC ne peuvent pas encore se
                                        connecter.
                                    </template>
                                </p>
                            </div>
                        </div>
                        <Button
                            type="button"
                            :variant="status.enabled ? 'outline' : 'default'"
                            :disabled="switching"
                            data-test="local-network-toggle"
                            @click="toggle"
                        >
                            <LoaderCircle
                                v-if="switching"
                                class="size-4 animate-spin"
                            />
                            {{
                                status.enabled
                                    ? 'Arrêter le partage'
                                    : 'Partager ce PC sur le réseau du cabinet'
                            }}
                        </Button>
                    </div>

                    <p
                        v-if="status.error"
                        class="text-sm text-destructive"
                        role="alert"
                    >
                        {{ status.error }}
                    </p>

                    <div
                        v-if="status.running"
                        class="space-y-3 rounded-xl border border-brand/30 bg-brand-soft/30 p-5"
                    >
                        <p class="text-sm font-semibold">
                            Adresse à saisir sur les autres PC
                        </p>
                        <div
                            v-if="primaryUrl"
                            class="flex flex-wrap items-center gap-3"
                        >
                            <span
                                class="font-mono text-2xl font-bold tracking-tight text-brand-deep select-all dark:text-brand"
                                data-test="local-network-address"
                                >{{ primaryUrl }}</span
                            >
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="copy(primaryUrl)"
                            >
                                <Copy class="size-4" />
                                {{ copied === primaryUrl ? 'Copié' : 'Copier' }}
                            </Button>
                        </div>
                        <p v-else class="text-sm text-amber-800">
                            Aucune adresse réseau privée détectée : vérifiez que
                            ce PC est relié au réseau du cabinet (câble ou
                            Wi-Fi).
                        </p>
                        <p
                            v-if="otherUrls.length > 0"
                            class="text-sm text-muted-foreground"
                        >
                            Autres adresses de ce PC :
                            <span
                                v-for="url in otherUrls"
                                :key="url"
                                class="mr-3 font-mono"
                                >{{ url }}</span
                            >
                        </p>
                        <p class="text-sm text-muted-foreground">
                            Sur les autres PC, la recherche automatique («
                            Rechercher sur le réseau ») trouve aussi ce poste :
                            {{ status.computer_name }}.
                        </p>
                    </div>

                    <div
                        v-if="status.available"
                        class="space-y-3 rounded-xl border border-border p-5 text-sm"
                    >
                        <div class="flex items-start gap-3">
                            <ShieldCheck
                                class="mt-0.5 size-5 shrink-0 text-brand"
                            />
                            <div class="space-y-2">
                                <p class="font-semibold">Pare-feu Windows</p>
                                <p class="text-muted-foreground">
                                    À la première activation, Windows peut
                                    demander si Drclick (php.exe) peut
                                    communiquer sur le réseau : cochez « Réseaux
                                    privés » puis cliquez sur « Autoriser
                                    l’accès ». Vous pouvez aussi l’autoriser
                                    directement (droits administrateur demandés)
                                    :
                                </p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :disabled="firewallBusy"
                                    data-test="local-network-firewall"
                                    @click="allowFirewall"
                                >
                                    <LoaderCircle
                                        v-if="firewallBusy"
                                        class="size-4 animate-spin"
                                    />
                                    Autoriser Drclick dans le pare-feu
                                </Button>
                                <p
                                    v-if="firewallDone"
                                    class="font-medium text-emerald-700"
                                >
                                    Pare-feu configuré pour les réseaux privés
                                    (ports {{ status.port }} et
                                    {{ status.discovery_port }}).
                                </p>
                                <p class="text-muted-foreground">
                                    Le réseau du cabinet doit être de type «
                                    Privé » : Paramètres Windows › Réseau et
                                    Internet › Ethernet ou Wi-Fi › Type de
                                    profil réseau › Privé.
                                </p>
                            </div>
                        </div>
                    </div>
                </template>

                <p v-if="error" class="text-sm text-destructive" role="alert">
                    {{ error }}
                </p>

                <div
                    class="space-y-3 rounded-xl border border-border p-5 text-sm"
                >
                    <div class="flex items-start gap-3">
                        <Users class="mt-0.5 size-5 shrink-0 text-brand" />
                        <div class="space-y-2">
                            <p class="font-semibold">
                                Ajouter le PC de l’assistante
                            </p>
                            <ol
                                class="list-decimal space-y-1 pl-5 text-muted-foreground"
                            >
                                <li>
                                    Ici, créez son compte dans
                                    <Link
                                        :href="staffUrl"
                                        class="font-semibold text-brand underline-offset-2 hover:underline"
                                        >Personnel</Link
                                    ><template v-if="seats">
                                        ({{ seats.used }} compte(s) sur
                                        {{ seats.limit }})</template
                                    >.
                                </li>
                                <li>
                                    Installez Drclick sur son PC, relié au même
                                    réseau (box, switch ou Wi-Fi du cabinet).
                                </li>
                                <li>
                                    Sur l’écran de connexion de son PC, cliquez
                                    sur « Rejoindre le poste principal », puis «
                                    Rechercher sur le réseau » ou saisissez
                                    l’adresse ci-dessus.
                                </li>
                                <li>
                                    Elle se connecte avec son propre e-mail et
                                    mot de passe : elle voit les mêmes dossiers.
                                </li>
                            </ol>
                            <p class="text-muted-foreground">
                                Laissez Drclick ouvert sur ce PC pendant les
                                consultations (fermer la fenêtre le garde actif
                                dans la zone de notification ; « Quitter »
                                l’arrête). Internet n’est pas nécessaire. Les
                                sauvegardes se font sur ce PC.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
