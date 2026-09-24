<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    BellRing,
    CalendarClock,
    CalendarDays,
    Check,
    CircleDollarSign,
    Copy,
    MessageCircle,
    MessageSquareText,
    Phone,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { createFrDzMoneyFormatter } from '@/pages/payments/display';

type Links = {
    whatsapp: string | null;
    sms: string | null;
    call: string | null;
    message: string;
};

type AppointmentRow = {
    id: number;
    time: string | null;
    patient_id: number;
    patient_name: string;
    patient_number: string | null;
    phone: string | null;
    reason: string | null;
    reminded_at: string | null;
    links: Links;
};

type RecallRow = {
    id: string;
    patient_id: number;
    patient_name: string;
    patient_number: string | null;
    phone: string | null;
    due_on: string;
    days: number;
    reason: string;
    contacted_at: string | null;
    links: Links;
};

type BalanceRow = {
    patient_id: number;
    patient_name: string;
    patient_number: string | null;
    phone: string | null;
    amount: number;
    count: number;
    age_days: number;
    links: Links;
};

const props = defineProps<{
    date: string;
    appointments: AppointmentRow[];
    recalls: RecallRow[];
    balances: BalanceRow[];
    currency: string;
    canManage: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Relances', href: '/app/reminders' }],
    },
});

const formatMoney = createFrDzMoneyFormatter(props.currency);
const tab = ref<'appointments' | 'recalls' | 'balances'>('appointments');
const selectedDate = ref(props.date);
const copied = ref<string | null>(null);

const tabs = computed(() =>
    [
        {
            key: 'appointments' as const,
            label: 'Rendez-vous',
            icon: CalendarDays,
            count: props.appointments.filter((row) => !row.reminded_at).length,
        },
        {
            key: 'recalls' as const,
            label: 'Rappels de suivi',
            icon: CalendarClock,
            count: props.recalls.filter((row) => row.days <= 0).length,
        },
        {
            key: 'balances' as const,
            label: 'Soldes impayés',
            icon: CircleDollarSign,
            count: props.balances.length,
            hidden: props.balances.length === 0,
        },
    ].filter((item) => !item.hidden),
);

const dateLabel = computed(() =>
    new Intl.DateTimeFormat('fr-DZ', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }).format(new Date(`${props.date}T00:00:00`)),
);

const changeDate = () => {
    router.get(
        '/app/reminders',
        { date: selectedDate.value },
        { preserveScroll: true, preserveState: false },
    );
};

const markAppointment = (row: AppointmentRow) => {
    if (!props.canManage || row.reminded_at) {
        return;
    }

    router.post(
        `/app/reminders/appointments/${row.id}`,
        {},
        { preserveScroll: true, preserveState: true },
    );
};

const updateRecall = (
    row: RecallRow,
    action: 'contacted' | 'done' | 'cancelled',
) => {
    router.patch(
        `/app/recalls/${row.id}`,
        { action },
        { preserveScroll: true, preserveState: true },
    );
};

const copyMessage = async (key: string, message: string) => {
    try {
        await navigator.clipboard.writeText(message);
        copied.value = key;
        window.setTimeout(() => {
            copied.value = null;
        }, 1500);
    } catch {
        copied.value = null;
    }
};

const shortDate = (date: string): string =>
    new Intl.DateTimeFormat('fr-DZ', { dateStyle: 'medium' }).format(
        new Date(`${date}T00:00:00`),
    );

const dueLabel = (days: number): string => {
    if (days < 0) {
        return `En retard de ${-days} j`;
    }

    return days === 0 ? 'Aujourd’hui' : `Dans ${days} j`;
};
</script>

<template>
    <Head title="Relances" />

    <div class="med-page">
        <header>
            <h1
                class="flex items-center gap-3 text-[2rem] leading-none font-bold tracking-tight text-[#111827] sm:text-[2.2rem] dark:text-slate-50"
            >
                <BellRing class="size-7 text-brand" />
                Relances
            </h1>
            <div class="mt-3 h-1 w-20 rounded-full bg-brand" />
            <p class="mt-3 max-w-3xl text-sm text-muted-foreground">
                Prévenez les patients en un clic : le message est déjà rédigé
                dans WhatsApp ou dans les SMS, il ne reste qu’à l’envoyer. Les
                rappels réduisent fortement les absences.
            </p>
        </header>

        <nav
            class="inline-flex w-fit flex-wrap gap-1 rounded-xl border bg-muted/40 p-1"
            aria-label="Types de relance"
        >
            <button
                v-for="item in tabs"
                :key="item.key"
                type="button"
                class="inline-flex h-9 items-center gap-2 rounded-lg px-3 text-sm font-semibold transition"
                :class="
                    tab === item.key
                        ? 'bg-background text-foreground shadow-sm'
                        : 'text-muted-foreground hover:text-foreground'
                "
                :aria-pressed="tab === item.key"
                @click="tab = item.key"
            >
                <component :is="item.icon" class="size-4" />
                {{ item.label }}
                <span
                    v-if="item.count > 0"
                    class="rounded-full bg-brand px-1.5 text-[11px] text-white tabular-nums"
                    >{{ item.count }}</span
                >
            </button>
        </nav>

        <!-- Appointments -->
        <section v-if="tab === 'appointments'" class="med-panel p-4">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-lg font-bold capitalize">{{ dateLabel }}</p>
                    <p class="text-sm text-muted-foreground">
                        {{ appointments.length }} rendez-vous à confirmer ·
                        {{
                            appointments.filter((row) => row.reminded_at).length
                        }}
                        déjà prévenu(s)
                    </p>
                </div>
                <div class="flex items-end gap-2">
                    <label class="grid gap-1 text-xs font-semibold">
                        Date
                        <Input
                            v-model="selectedDate"
                            type="date"
                            class="w-44"
                            @change="changeDate"
                        />
                    </label>
                </div>
            </div>

            <ul v-if="appointments.length" class="divide-y rounded-xl border">
                <li
                    v-for="row in appointments"
                    :key="row.id"
                    class="flex flex-wrap items-center justify-between gap-3 p-3"
                    :class="row.reminded_at ? 'bg-emerald-500/5' : ''"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <span
                            class="w-14 shrink-0 font-mono text-sm font-bold text-brand tabular-nums"
                            >{{ row.time ?? '—' }}</span
                        >
                        <span class="min-w-0">
                            <Link
                                :href="`/app/patients/${row.patient_id}`"
                                class="block truncate font-semibold hover:underline"
                                >{{ row.patient_name }}</Link
                            >
                            <span
                                class="block truncate text-xs text-muted-foreground"
                            >
                                {{ row.phone ?? 'Pas de téléphone' }}
                                <template v-if="row.reason">
                                    · {{ row.reason }}</template
                                >
                            </span>
                        </span>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span
                            v-if="row.reminded_at"
                            class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-400"
                        >
                            <Check class="size-3.5" /> Prévenu
                        </span>
                        <Button
                            v-if="row.links.whatsapp"
                            size="sm"
                            class="bg-[#1f8f4e] text-white hover:bg-[#18743f]"
                            as-child
                        >
                            <a
                                :href="row.links.whatsapp"
                                target="_blank"
                                rel="noopener"
                                @click="markAppointment(row)"
                            >
                                <MessageCircle class="size-4" /> WhatsApp
                            </a>
                        </Button>
                        <Button
                            v-if="row.links.sms"
                            size="sm"
                            variant="outline"
                            as-child
                        >
                            <a
                                :href="row.links.sms"
                                @click="markAppointment(row)"
                            >
                                <MessageSquareText class="size-4" /> SMS
                            </a>
                        </Button>
                        <Button
                            v-if="row.links.call"
                            size="icon"
                            variant="outline"
                            as-child
                            :aria-label="`Appeler ${row.patient_name}`"
                        >
                            <a :href="row.links.call"
                                ><Phone class="size-4"
                            /></a>
                        </Button>
                        <Button
                            size="icon"
                            variant="ghost"
                            :aria-label="`Copier le message pour ${row.patient_name}`"
                            :title="row.links.message"
                            @click="
                                copyMessage(`a${row.id}`, row.links.message)
                            "
                        >
                            <Check
                                v-if="copied === `a${row.id}`"
                                class="size-4 text-emerald-600"
                            />
                            <Copy v-else class="size-4" />
                        </Button>
                        <Button
                            v-if="canManage && !row.reminded_at"
                            size="sm"
                            variant="ghost"
                            @click="markAppointment(row)"
                        >
                            Marquer prévenu
                        </Button>
                    </div>
                </li>
            </ul>
            <p
                v-else
                class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
            >
                Aucun rendez-vous à confirmer ce jour-là.
            </p>
        </section>

        <!-- Recalls -->
        <section v-if="tab === 'recalls'" class="med-panel p-4">
            <p class="mb-4 text-sm text-muted-foreground">
                Rappels en retard et à venir dans les 30 prochains jours.
                Programmez-les depuis la fiche du patient (bouton « Rappel »).
            </p>
            <ul v-if="recalls.length" class="divide-y rounded-xl border">
                <li
                    v-for="row in recalls"
                    :key="row.id"
                    class="flex flex-wrap items-center justify-between gap-3 p-3"
                >
                    <div class="min-w-0">
                        <Link
                            :href="`/app/patients/${row.patient_id}`"
                            class="font-semibold hover:underline"
                            >{{ row.patient_name }}</Link
                        >
                        <span
                            class="ml-2 rounded-full px-2 py-0.5 text-[11px] font-semibold"
                            :class="
                                row.days < 0
                                    ? 'bg-rose-500/10 text-rose-700 dark:text-rose-400'
                                    : row.days <= 7
                                      ? 'bg-amber-500/10 text-amber-700 dark:text-amber-400'
                                      : 'bg-muted text-muted-foreground'
                            "
                            >{{ dueLabel(row.days) }}</span
                        >
                        <p class="text-xs text-muted-foreground">
                            {{ shortDate(row.due_on) }} · {{ row.reason }} ·
                            {{ row.phone ?? 'Pas de téléphone' }}
                            <template v-if="row.contacted_at">
                                · contacté</template
                            >
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Button
                            v-if="row.links.whatsapp"
                            size="sm"
                            class="bg-[#1f8f4e] text-white hover:bg-[#18743f]"
                            as-child
                        >
                            <a
                                :href="row.links.whatsapp"
                                target="_blank"
                                rel="noopener"
                                @click="updateRecall(row, 'contacted')"
                            >
                                <MessageCircle class="size-4" /> WhatsApp
                            </a>
                        </Button>
                        <Button
                            v-if="row.links.sms"
                            size="sm"
                            variant="outline"
                            as-child
                        >
                            <a
                                :href="row.links.sms"
                                @click="updateRecall(row, 'contacted')"
                            >
                                <MessageSquareText class="size-4" /> SMS
                            </a>
                        </Button>
                        <Button
                            v-if="row.links.call"
                            size="icon"
                            variant="outline"
                            as-child
                            :aria-label="`Appeler ${row.patient_name}`"
                        >
                            <a :href="row.links.call"
                                ><Phone class="size-4"
                            /></a>
                        </Button>
                        <Button
                            size="sm"
                            variant="secondary"
                            @click="updateRecall(row, 'done')"
                        >
                            <Check class="size-4" /> Fait
                        </Button>
                        <Button
                            size="icon"
                            variant="ghost"
                            :aria-label="`Annuler le rappel de ${row.patient_name}`"
                            title="Annuler ce rappel"
                            @click="updateRecall(row, 'cancelled')"
                        >
                            <X class="size-4" />
                        </Button>
                    </div>
                </li>
            </ul>
            <p
                v-else
                class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
            >
                Aucun rappel à faire pour le moment.
            </p>
        </section>

        <!-- Balances -->
        <section v-if="tab === 'balances'" class="med-panel p-4">
            <p class="mb-4 text-sm text-muted-foreground">
                Patients avec un solde à régler, du plus élevé au plus faible.
                Message courtois, sans détail médical.
            </p>
            <ul class="divide-y rounded-xl border">
                <li
                    v-for="row in balances"
                    :key="row.patient_id"
                    class="flex flex-wrap items-center justify-between gap-3 p-3"
                >
                    <div class="min-w-0">
                        <Link
                            :href="`/app/patients/${row.patient_id}`"
                            class="font-semibold hover:underline"
                            >{{ row.patient_name }}</Link
                        >
                        <p class="text-xs text-muted-foreground">
                            {{ row.count }} consultation(s) · depuis
                            {{ row.age_days }} j ·
                            {{ row.phone ?? 'Pas de téléphone' }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span
                            class="mr-2 font-bold text-amber-700 tabular-nums dark:text-amber-400"
                            >{{ formatMoney(row.amount) }}</span
                        >
                        <Button
                            v-if="row.links.whatsapp"
                            size="sm"
                            class="bg-[#1f8f4e] text-white hover:bg-[#18743f]"
                            as-child
                        >
                            <a
                                :href="row.links.whatsapp"
                                target="_blank"
                                rel="noopener"
                            >
                                <MessageCircle class="size-4" /> WhatsApp
                            </a>
                        </Button>
                        <Button
                            v-if="row.links.sms"
                            size="sm"
                            variant="outline"
                            as-child
                        >
                            <a :href="row.links.sms">
                                <MessageSquareText class="size-4" /> SMS
                            </a>
                        </Button>
                        <Button size="sm" variant="outline" as-child>
                            <Link
                                :href="`/app/payments?status=debt&search=${encodeURIComponent(row.patient_number ?? row.patient_name)}`"
                                >Encaisser</Link
                            >
                        </Button>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
