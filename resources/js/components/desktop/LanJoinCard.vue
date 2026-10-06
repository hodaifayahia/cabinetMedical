<script setup lang="ts">
import {
    LoaderCircle,
    MonitorSmartphone,
    Network,
    RotateCcw,
    Search,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { DiscoveredLanHost, LanHostStatus } from '@/lib/lanNetwork';
import {
    connectToLanHost,
    discoverLanHosts,
    isDesktopShell,
    lanHostLabel,
    lanHostStatus,
    normalizeLanHostAddress,
    readableNativeError,
    returnToLocalMode,
    runtimeModeStatus,
} from '@/lib/lanNetwork';

/**
 * Sign-in companion for the installed app (ADR-005).
 *
 * - On a poste secondaire (attached to the poste principal) it says which PC
 *   holds the data and offers "Revenir au mode autonome".
 * - On a PC that owns its data it offers "Rejoindre le poste principal":
 *   find the doctor's PC on the LAN (or type its address) and use its
 *   database. If this PC is itself sharing, it shows the address to type on
 *   the other PCs instead.
 *
 * Renders nothing in a browser or in cloud mode.
 */

const mode = ref<string | null>(null);
const attachedTo = ref<string | null>(null);
const host = ref<LanHostStatus | null>(null);
const open = ref(false);
const address = ref('');
const searching = ref(false);
const searched = ref(false);
const found = ref<DiscoveredLanHost[]>([]);
const busy = ref(false);
const error = ref('');
const restarting = ref(false);

const sharingUrl = computed(() =>
    host.value?.running ? (host.value.addresses[0]?.url ?? null) : null,
);

onMounted(async () => {
    if (!isDesktopShell()) {
        return;
    }

    const status = await runtimeModeStatus();
    mode.value = status?.mode ?? null;
    attachedTo.value = status?.mode === 'attach' ? status.url : null;

    if (status?.mode === 'local') {
        try {
            host.value = await lanHostStatus();
        } catch {
            host.value = null;
        }
    }
});

async function search(): Promise<void> {
    error.value = '';
    searching.value = true;

    try {
        found.value = await discoverLanHosts();
    } catch (failure) {
        found.value = [];
        error.value = readableNativeError(
            failure,
            'La recherche des postes du cabinet a échoué.',
        );
    } finally {
        searching.value = false;
        searched.value = true;
    }
}

async function join(target: string): Promise<void> {
    const url = normalizeLanHostAddress(target);

    if (url === null) {
        error.value =
            'Saisissez l’adresse affichée sur le poste principal, par exemple 192.168.1.10.';

        return;
    }

    error.value = '';
    busy.value = true;

    try {
        await connectToLanHost(url);
        restarting.value = true;
    } catch (failure) {
        error.value = readableNativeError(
            failure,
            'Impossible de joindre ce poste.',
        );
    } finally {
        busy.value = false;
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
    busy.value = true;

    try {
        await returnToLocalMode();
        restarting.value = true;
    } catch (failure) {
        error.value = readableNativeError(failure);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section
        v-if="mode === 'attach'"
        class="mt-6 rounded-2xl border border-border bg-muted/40 p-4 text-sm"
        data-test="lan-attached-card"
    >
        <div class="flex items-start gap-3">
            <Network
                class="mt-0.5 size-5 shrink-0 text-brand"
                aria-hidden="true"
            />
            <div class="min-w-0 flex-1 space-y-2">
                <p class="font-semibold text-foreground">
                    Connecté au poste principal
                    <span class="font-mono">{{
                        lanHostLabel(attachedTo)
                    }}</span>
                </p>
                <p class="text-muted-foreground">
                    Les dossiers sont enregistrés sur le PC du médecin.
                    Connectez-vous avec votre propre compte.
                </p>
                <p v-if="restarting" class="font-medium text-brand">
                    Drclick redémarre…
                </p>
                <Button
                    v-else
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="busy"
                    data-test="lan-back-to-local"
                    @click="backToLocal"
                >
                    <RotateCcw class="size-4" aria-hidden="true" />
                    Revenir au mode autonome
                </Button>
                <p v-if="error" class="text-destructive" role="alert">
                    {{ error }}
                </p>
            </div>
        </div>
    </section>

    <section
        v-else-if="mode === 'local' && sharingUrl"
        class="mt-6 rounded-2xl border border-border bg-muted/40 p-4 text-sm"
        data-test="lan-sharing-card"
    >
        <div class="flex items-start gap-3">
            <MonitorSmartphone
                class="mt-0.5 size-5 shrink-0 text-brand"
                aria-hidden="true"
            />
            <p class="text-muted-foreground">
                Ce PC est le poste principal du cabinet. Sur les autres PC,
                choisissez « Rejoindre le poste principal » et saisissez
                <span class="font-mono font-semibold text-foreground">{{
                    sharingUrl
                }}</span>
            </p>
        </div>
    </section>

    <section
        v-else-if="mode === 'local'"
        class="mt-6 rounded-2xl border border-border p-4 text-sm"
        data-test="lan-join-card"
    >
        <button
            v-if="!open"
            type="button"
            class="flex w-full items-center gap-3 text-left font-semibold text-foreground"
            data-test="lan-join-open"
            @click="open = true"
        >
            <Network class="size-5 shrink-0 text-brand" aria-hidden="true" />
            <span class="flex-1">
                Plusieurs PC au cabinet ? Rejoindre le poste principal
            </span>
        </button>

        <div v-else class="space-y-4">
            <div class="flex items-start gap-3">
                <Network
                    class="mt-0.5 size-5 shrink-0 text-brand"
                    aria-hidden="true"
                />
                <div class="space-y-1">
                    <p class="font-semibold text-foreground">
                        Rejoindre le poste principal
                    </p>
                    <p class="text-muted-foreground">
                        Ce PC utilisera la base de données du PC du médecin, sur
                        le réseau du cabinet. Les données déjà saisies sur ce PC
                        y restent et ne sont pas fusionnées.
                    </p>
                </div>
            </div>

            <p v-if="restarting" class="font-medium text-brand" role="status">
                Poste principal trouvé. Drclick redémarre…
            </p>

            <template v-else>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="searching || busy"
                    data-test="lan-search"
                    @click="search"
                >
                    <LoaderCircle
                        v-if="searching"
                        class="size-4 animate-spin"
                        aria-hidden="true"
                    />
                    <Search v-else class="size-4" aria-hidden="true" />
                    Rechercher sur le réseau
                </Button>

                <ul v-if="found.length > 0" class="grid gap-2">
                    <li v-for="candidate in found" :key="candidate.url">
                        <Button
                            type="button"
                            class="w-full justify-start"
                            :disabled="busy"
                            data-test="lan-found-host"
                            @click="join(candidate.url)"
                        >
                            {{ candidate.name }} —
                            <span class="font-mono">{{
                                candidate.address
                            }}</span>
                        </Button>
                    </li>
                </ul>
                <p
                    v-else-if="searched && !searching"
                    class="text-muted-foreground"
                >
                    Aucun poste principal trouvé automatiquement. Saisissez son
                    adresse, affichée sur le PC du médecin dans Configuration ›
                    Réseau local.
                </p>

                <form class="grid gap-2" @submit.prevent="join(address)">
                    <Label for="lan-host-address"
                        >Adresse du poste principal</Label
                    >
                    <div class="flex gap-2">
                        <Input
                            id="lan-host-address"
                            v-model="address"
                            inputmode="url"
                            autocomplete="off"
                            spellcheck="false"
                            placeholder="192.168.1.10"
                            class="h-10"
                        />
                        <Button
                            type="submit"
                            :disabled="busy || address.trim() === ''"
                            data-test="lan-join-submit"
                        >
                            <LoaderCircle
                                v-if="busy"
                                class="size-4 animate-spin"
                                aria-hidden="true"
                            />
                            Se connecter
                        </Button>
                    </div>
                </form>
            </template>

            <p v-if="error" class="text-destructive" role="alert">
                {{ error }}
            </p>
        </div>
    </section>
</template>
