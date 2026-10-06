<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Copy, Download, KeyRound, Printer } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps<{
    remaining: number;
    generatedAt: string | null;
}>();

const page = usePage();
const form = useForm({});
const freshCodes = ref<string[]>([]);
const copied = ref(false);

const readCodes = (flash: unknown): void => {
    const codes = (flash as { recoveryCodes?: unknown } | null)?.recoveryCodes;

    if (Array.isArray(codes)) {
        freshCodes.value = codes.filter(
            (code): code is string => typeof code === 'string',
        );
    }
};

const generatedLabel = computed(() =>
    props.generatedAt
        ? new Date(props.generatedAt).toLocaleDateString('fr-FR', {
              day: '2-digit',
              month: 'long',
              year: 'numeric',
          })
        : null,
);

const accountLabel = computed(() => {
    const user = page.props.auth?.user as
        { name?: string; email?: string | null } | undefined;

    return [user?.name, user?.email].filter(Boolean).join(' — ');
});

const sheet = (): string =>
    [
        'Drclick — codes de secours',
        accountLabel.value,
        `Créés le ${new Date().toLocaleString('fr-FR')}`,
        '',
        'Chaque code ne sert qu’une fois. Gardez cette feuille en lieu sûr.',
        'Utilisation : écran de connexion › Mot de passe oublié › Code de secours.',
        '',
        ...freshCodes.value.map((code, index) => `${index + 1}. ${code}`),
        '',
    ].join('\n');

const generate = (): void => {
    form.post('/settings/recovery-codes', { preserveScroll: true });
};

const copy = async (): Promise<void> => {
    try {
        await navigator.clipboard.writeText(sheet());
        copied.value = true;
        window.setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
};

const download = (): void => {
    const url = URL.createObjectURL(
        new Blob([sheet()], { type: 'text/plain;charset=utf-8' }),
    );
    const link = document.createElement('a');
    link.href = url;
    link.download = 'drclick-codes-de-secours.txt';
    link.click();
    URL.revokeObjectURL(url);
};

const print = (): void => {
    window.print();
};

let stopFlash: () => void = () => undefined;

onMounted(() => {
    readCodes((page as unknown as { flash?: unknown }).flash);
    stopFlash = router.on('flash', (event) =>
        readCodes((event as CustomEvent).detail?.flash),
    );
});

onBeforeUnmount(() => stopFlash());
</script>

<template>
    <section
        class="space-y-6"
        aria-labelledby="recovery-codes-heading"
        data-test="recovery-codes"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                id="recovery-codes-heading"
                variant="small"
                title="Codes de secours"
                description="Si vous oubliez votre mot de passe et votre code PIN, un de ces codes permet de choisir un nouveau mot de passe, sans e-mail ni Internet."
            />
            <Badge :variant="remaining > 0 ? 'default' : 'outline'">
                {{
                    remaining > 0
                        ? `${remaining} code${remaining > 1 ? 's' : ''} restant${remaining > 1 ? 's' : ''}`
                        : 'Aucun code'
                }}
            </Badge>
        </div>

        <p
            v-if="remaining === 0 && freshCodes.length === 0"
            class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
        >
            Créez vos codes maintenant et rangez la feuille avec les documents
            du cabinet : c’est votre solution si un jour vous oubliez tout.
        </p>
        <p
            v-else-if="generatedLabel && freshCodes.length === 0"
            class="text-sm text-muted-foreground"
        >
            Codes créés le {{ generatedLabel }}. Les codes ne sont jamais
            affichés de nouveau : en créer de nouveaux annule les anciens.
        </p>

        <div
            v-if="freshCodes.length > 0"
            class="recovery-sheet space-y-4 rounded-2xl border border-brand/30 bg-brand-soft/40 p-5 dark:bg-brand-soft/10"
            data-test="recovery-codes-sheet"
        >
            <p class="text-sm font-semibold text-slate-900 dark:text-white">
                Notez ces codes maintenant : ils ne seront plus affichés.
            </p>
            <ol class="grid gap-2 sm:grid-cols-2">
                <li
                    v-for="(code, index) in freshCodes"
                    :key="code"
                    class="flex items-center gap-3 rounded-xl bg-white px-3 py-2 font-mono text-base font-bold tracking-wider text-slate-900 dark:bg-slate-950 dark:text-white"
                >
                    <span class="w-5 text-xs text-slate-400">{{
                        index + 1
                    }}</span>
                    {{ code }}
                </li>
            </ol>
            <div class="flex flex-wrap gap-2 print:hidden">
                <Button type="button" variant="outline" @click="print">
                    <Printer class="size-4" aria-hidden="true" />
                    Imprimer
                </Button>
                <Button type="button" variant="outline" @click="download">
                    <Download class="size-4" aria-hidden="true" />
                    Enregistrer (.txt)
                </Button>
                <Button type="button" variant="outline" @click="copy">
                    <Copy class="size-4" aria-hidden="true" />
                    {{ copied ? 'Copié' : 'Copier' }}
                </Button>
            </div>
        </div>

        <Button
            type="button"
            :variant="remaining > 0 ? 'outline' : 'default'"
            :disabled="form.processing"
            data-test="recovery-codes-generate"
            @click="generate"
        >
            <Spinner v-if="form.processing" />
            <KeyRound v-else class="size-4" aria-hidden="true" />
            {{
                remaining > 0 || generatedLabel
                    ? 'Créer de nouveaux codes'
                    : 'Créer mes codes de secours'
            }}
        </Button>
    </section>
</template>
