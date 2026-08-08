<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CalendarDays,
    ChevronRight,
    Clock,
    Search,
    Stethoscope,
    User,
    Wallet,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Patients', href: '/app/patients' }],
    },
});

type HistoryConsultation = {
    id: number;
    consulted_at: string | null;
    date_key: string | null;
    time_label: string | null;
    status: string | null;
    provider_name: string | null;
    motif: string | null;
    diagnostic: string | null;
    payment_amount: number | null;
    is_paid: boolean;
};

const props = defineProps<{
    patient: {
        id: number;
        patient_number: string | null;
        full_name: string | null;
    };
    currency: string;
    consultations: HistoryConsultation[];
}>();

const search = ref('');

const statusLabels: Record<string, string> = {
    in_progress: 'En cours',
    completed: 'Terminée',
    draft: 'Brouillon',
};

const statusStyles: Record<string, string> = {
    in_progress:
        'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
    completed:
        'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
    draft: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
};

const statusLabel = (status: string | null): string =>
    status ? (statusLabels[status] ?? status.replace(/_/g, ' ')) : '—';

const normalized = (value: string | null | undefined): string =>
    (value ?? '').trim().toLocaleLowerCase();

const filtered = computed(() => {
    const query = normalized(search.value);

    if (query === '') {
        return props.consultations;
    }

    return props.consultations.filter((consultation) =>
        [
            consultation.motif,
            consultation.diagnostic,
            consultation.provider_name,
            statusLabel(consultation.status),
        ]
            .map(normalized)
            .join(' ')
            .includes(query),
    );
});

// Group consultations by consultation date so the timeline reads as a
// reverse-chronological list of days (requirement #3).
const groupedByDate = computed(() => {
    const groups = new Map<string, HistoryConsultation[]>();

    for (const consultation of filtered.value) {
        const key = consultation.date_key ?? 'unknown';
        const bucket = groups.get(key);

        if (bucket) {
            bucket.push(consultation);
        } else {
            groups.set(key, [consultation]);
        }
    }

    return Array.from(groups.entries()).map(([date, items]) => ({
        date,
        items,
    }));
});

const formatDayHeading = (value: string): string => {
    if (value === 'unknown') {
        return 'Date inconnue';
    }

    return new Date(`${value}T00:00:00`).toLocaleDateString('fr-FR', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
};

const formatAmount = (amount: number | null): string =>
    amount === null
        ? '—'
        : `${amount.toLocaleString('fr-FR', {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
          })} ${props.currency}`;
</script>

<template>
    <Head :title="`Historique — ${patient.full_name}`" />

    <div class="med-page">
        <section class="med-panel overflow-hidden">
            <div
                class="border-b border-sidebar-border/70 p-6 dark:border-sidebar-border"
            >
                <div
                    class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
                >
                    <Heading
                        title="Historique des consultations"
                        :description="`${patient.full_name ?? 'Patient'} · ${patient.patient_number ?? 'Dossier patient'}`"
                    />
                    <Button variant="outline" size="sm" as-child>
                        <Link :href="`/app/patients/${patient.id}`">
                            <ArrowLeft class="size-4" />
                            Retour au dossier
                        </Link>
                    </Button>
                </div>

                <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        class="rounded-xl border border-sidebar-border/70 bg-muted/20 p-4 dark:border-sidebar-border"
                    >
                        <div
                            class="flex items-center justify-between text-muted-foreground"
                        >
                            <span
                                class="text-xs font-medium tracking-wide uppercase"
                                >Total des consultations</span
                            >
                            <Stethoscope class="size-4" />
                        </div>
                        <p class="mt-2 text-2xl font-semibold">
                            {{ consultations.length }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="space-y-6 p-6">
                <div class="relative max-w-md">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="search"
                        class="h-10 pl-9"
                        placeholder="Rechercher par motif, diagnostic ou médecin…"
                    />
                </div>

                <p
                    v-if="filtered.length === 0"
                    class="rounded-xl border border-dashed border-sidebar-border/70 p-10 text-center text-sm text-muted-foreground dark:border-sidebar-border"
                >
                    Aucune consultation enregistrée pour ce patient.
                </p>

                <div
                    v-for="group in groupedByDate"
                    v-else
                    :key="group.date"
                    class="space-y-3"
                >
                    <div
                        class="flex items-center gap-2 text-sm font-semibold text-foreground"
                    >
                        <CalendarDays class="size-4 text-muted-foreground" />
                        <span class="capitalize">{{
                            formatDayHeading(group.date)
                        }}</span>
                    </div>

                    <ol
                        class="space-y-3 border-l border-sidebar-border/70 pl-4 dark:border-sidebar-border"
                    >
                        <li
                            v-for="consultation in group.items"
                            :key="consultation.id"
                        >
                            <Link
                                :href="`/app/consultation-history/${consultation.id}`"
                                class="group flex items-start gap-4 rounded-xl border border-sidebar-border/70 bg-background p-4 transition-colors hover:border-[#3e739f]/50 hover:bg-muted/30 dark:border-sidebar-border"
                            >
                                <div
                                    class="flex items-center gap-1.5 text-sm font-semibold text-foreground tabular-nums"
                                >
                                    <Clock
                                        class="size-3.5 text-muted-foreground"
                                    />
                                    {{ consultation.time_label ?? '—' }}
                                </div>

                                <div class="min-w-0 flex-1 space-y-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span
                                            class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                            :class="
                                                statusStyles[
                                                    consultation.status ?? ''
                                                ] ??
                                                'bg-muted text-muted-foreground'
                                            "
                                        >
                                            {{ statusLabel(consultation.status) }}
                                        </span>
                                        <span
                                            v-if="consultation.provider_name"
                                            class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                                        >
                                            <User class="size-3" />
                                            {{ consultation.provider_name }}
                                        </span>
                                        <span
                                            class="inline-flex items-center gap-1 text-xs"
                                            :class="
                                                consultation.is_paid
                                                    ? 'text-emerald-600 dark:text-emerald-400'
                                                    : 'text-amber-600 dark:text-amber-400'
                                            "
                                        >
                                            <Wallet class="size-3" />
                                            {{ formatAmount(consultation.payment_amount) }}
                                            <span v-if="consultation.payment_amount !== null">
                                                ·
                                                {{
                                                    consultation.is_paid
                                                        ? 'Payé'
                                                        : 'Impayé'
                                                }}
                                            </span>
                                        </span>
                                    </div>
                                    <p
                                        class="truncate text-sm font-medium text-foreground"
                                    >
                                        {{ consultation.motif ?? 'Motif non renseigné' }}
                                    </p>
                                    <p
                                        v-if="consultation.diagnostic"
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{ consultation.diagnostic }}
                                    </p>
                                </div>

                                <ChevronRight
                                    class="size-4 shrink-0 self-center text-muted-foreground transition-transform group-hover:translate-x-0.5"
                                />
                            </Link>
                        </li>
                    </ol>
                </div>
            </div>
        </section>
    </div>
</template>
