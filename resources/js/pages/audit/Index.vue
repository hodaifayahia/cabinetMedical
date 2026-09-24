<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Filter, RefreshCw, ScrollText } from '@lucide/vue';
import { reactive } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { paymentPaginationLabel } from '@/pages/payments/display';

type Log = {
    id: number;
    at: string | null;
    action: string;
    label: string;
    user: string | null;
    subject: string | null;
    details: string;
    ip: string | null;
};

const props = defineProps<{
    logs: {
        data: Log[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: { action: string; user: string; from: string; to: string };
    actions: { value: string; label: string }[];
    users: { id: number; name: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Journal d’audit', href: '/app/audit-logs' }],
    },
});

const local = reactive({ ...props.filters });

const apply = () => {
    router.get(
        '/app/audit-logs',
        Object.fromEntries(
            Object.entries(local).filter(([, value]) => value !== ''),
        ),
        { preserveScroll: true, preserveState: true, replace: true },
    );
};

const reset = () => router.get('/app/audit-logs');

const dateLabel = (date: string | null): string =>
    date
        ? new Intl.DateTimeFormat('fr-DZ', {
              dateStyle: 'short',
              timeStyle: 'short',
          }).format(new Date(date))
        : '—';
</script>

<template>
    <Head title="Journal d’audit" />

    <div class="med-page">
        <header>
            <h1
                class="flex items-center gap-3 text-[2rem] leading-none font-bold tracking-tight text-[#111827] sm:text-[2.2rem] dark:text-slate-50"
            >
                <ScrollText class="size-7 text-brand" />
                Journal d’audit
            </h1>
            <div class="mt-3 h-1 w-20 rounded-full bg-brand" />
            <p class="mt-3 text-sm text-muted-foreground">
                Qui a fait quoi et quand : paiements, remboursements, charges,
                ordonnances, fiches patients. Le journal ne peut pas être
                modifié.
            </p>
        </header>

        <section class="med-panel p-4">
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                <label class="grid gap-1 text-xs font-semibold">
                    Action
                    <select
                        v-model="local.action"
                        class="h-10 rounded-xl border border-input bg-background px-3 text-sm font-normal shadow-sm"
                    >
                        <option value="">Toutes les actions</option>
                        <option
                            v-for="action in actions"
                            :key="action.value"
                            :value="action.value"
                        >
                            {{ action.label }}
                        </option>
                    </select>
                </label>
                <label class="grid gap-1 text-xs font-semibold">
                    Utilisateur
                    <select
                        v-model="local.user"
                        class="h-10 rounded-xl border border-input bg-background px-3 text-sm font-normal shadow-sm"
                    >
                        <option value="">Tous</option>
                        <option
                            v-for="user in users"
                            :key="user.id"
                            :value="String(user.id)"
                        >
                            {{ user.name }}
                        </option>
                    </select>
                </label>
                <label class="grid gap-1 text-xs font-semibold">
                    Du
                    <Input v-model="local.from" type="date" />
                </label>
                <label class="grid gap-1 text-xs font-semibold">
                    Au
                    <Input v-model="local.to" type="date" />
                </label>
                <div class="flex items-end gap-2">
                    <Button class="flex-1" @click="apply">
                        <Filter class="size-4" /> Filtrer
                    </Button>
                    <Button
                        variant="outline"
                        size="icon"
                        aria-label="Réinitialiser"
                        @click="reset"
                    >
                        <RefreshCw class="size-4" />
                    </Button>
                </div>
            </div>

            <div class="med-table-wrap mt-4">
                <table class="med-table">
                    <thead>
                        <tr>
                            <th class="px-4 py-2 font-medium">Date</th>
                            <th class="px-4 py-2 font-medium">Utilisateur</th>
                            <th class="px-4 py-2 font-medium">Action</th>
                            <th class="px-4 py-2 font-medium">Détails</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="log in logs.data"
                            :key="log.id"
                            class="bg-background align-top"
                        >
                            <td
                                class="px-4 py-2 whitespace-nowrap text-muted-foreground tabular-nums"
                            >
                                {{ dateLabel(log.at) }}
                            </td>
                            <td class="px-4 py-2">{{ log.user ?? '—' }}</td>
                            <td class="px-4 py-2">
                                <span class="font-semibold">{{
                                    log.label
                                }}</span>
                                <span
                                    v-if="log.subject"
                                    class="block text-xs text-muted-foreground"
                                    >{{ log.subject }}</span
                                >
                            </td>
                            <td
                                class="max-w-md px-4 py-2 text-xs text-muted-foreground"
                            >
                                {{ log.details || '—' }}
                            </td>
                        </tr>
                        <tr v-if="!logs.data.length">
                            <td
                                colspan="4"
                                class="px-4 py-10 text-center text-sm text-muted-foreground"
                            >
                                Aucune entrée pour ces filtres.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-muted-foreground">
                    {{ logs.total }} entrée(s)
                </p>
                <div class="flex flex-wrap gap-1">
                    <template v-for="link in logs.links" :key="link.label">
                        <Button
                            v-if="link.url"
                            as-child
                            size="sm"
                            :variant="link.active ? 'default' : 'outline'"
                        >
                            <Link :href="link.url" preserve-scroll>
                                <span
                                    v-html="paymentPaginationLabel(link.label)"
                                />
                            </Link>
                        </Button>
                    </template>
                </div>
            </div>
        </section>
    </div>
</template>
