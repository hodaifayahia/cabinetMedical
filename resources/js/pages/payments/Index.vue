<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Banknote,
    CircleDollarSign,
    FileText,
    Filter,
    Pencil,
    Printer,
    RefreshCw,
    Search,
    UserRound,
    WalletCards,
} from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
    createFrDzMoneyFormatter,
    paymentDateLabel,
    paymentMethodLabel,
    paymentPaginationLabel,
    paymentStatusLabel,
} from '@/pages/payments/display';

type Payment = {
    id: number;
    patient_id: number;
    patient_number: string | null;
    patient_name: string;
    initials: string;
    user_name: string | null;
    service: string;
    method: string | null;
    amount: number;
    paid: number;
    adjustment: number;
    outstanding: number;
    status: 'paid' | 'partial' | 'unpaid';
    is_paid: boolean;
    notes: string | null;
    installments: {
        id: string;
        amount: number;
        method: string | null;
        notes: string | null;
        received_at: string | null;
        received_by: string | null;
    }[];
    date: string | null;
    date_label: string | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

const props = defineProps<{
    payments: {
        data: Payment[];
        links: PaginationLink[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: {
        from: string;
        to: string;
        user: string;
        search: string;
        status: string;
        method: string;
    };
    summary: {
        today: number;
        paid: number;
        outstanding: number;
    };
    currency: string;
    users: { id: number; name: string }[];
    methods: string[];
    services: { label: string; amount: number }[];
    canEdit: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Paiements', href: '/app/payments' }],
    },
});

const localFilters = reactive({ ...props.filters });
const showEditor = ref(false);
const selectedPayment = ref<Payment | null>(null);

const newPaymentReference = (): string => {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
        return crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-8xxx-xxxxxxxxxxxx'.replace(/[x]/g, () =>
        Math.floor(Math.random() * 16).toString(16),
    );
};

const paymentForm = useForm({
    amount: '',
    paid_today: '',
    method: '',
    service: '',
    notes: '',
    settlement: 'debt' as 'debt' | 'settled',
    client_reference: newPaymentReference(),
});

const formatMoney = createFrDzMoneyFormatter(props.currency);

const applyFilters = () => {
    router.get(
        '/app/payments',
        {
            from: localFilters.from,
            to: localFilters.to,
            user: localFilters.user || undefined,
            search: localFilters.search || undefined,
            status: localFilters.status,
            method: localFilters.method || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const resetFilters = () => {
    const now = new Date();
    localFilters.from = new Date(now.getFullYear(), now.getMonth(), 1)
        .toISOString()
        .slice(0, 10);
    localFilters.to = now.toISOString().slice(0, 10);
    localFilters.user = '';
    localFilters.search = '';
    localFilters.status = 'all';
    localFilters.method = '';
    applyFilters();
};

const reportUrl = computed(() => {
    const params = new URLSearchParams();

    Object.entries(localFilters).forEach(([key, value]) => {
        if (value) {
            params.set(key, value);
        }
    });

    return '/app/payments/print?' + params.toString();
});

const openEditor = (payment: Payment) => {
    selectedPayment.value = payment;
    paymentForm.amount = String(payment.amount);
    paymentForm.method = payment.method ?? '';
    paymentForm.service = payment.service;
    paymentForm.paid_today = '';
    paymentForm.notes = '';
    paymentForm.settlement = 'debt';
    paymentForm.client_reference = newPaymentReference();
    paymentForm.clearErrors();
    showEditor.value = true;
};

const projectedOutstanding = computed(() =>
    Math.max(
        0,
        (selectedPayment.value?.outstanding ?? 0) -
            Number(paymentForm.paid_today || 0),
    ),
);

const showAllDebts = () => {
    localFilters.from = '';
    localFilters.to = '';
    localFilters.status = 'debt';
    applyFilters();
};

const chooseService = (value: string) => {
    paymentForm.service = value;
    const service = props.services.find((item) => item.label === value);

    if (service) {
        paymentForm.amount = String(service.amount);
    }
};

const savePayment = () => {
    if (!selectedPayment.value) {
        return;
    }

    paymentForm.patch('/app/payments/' + selectedPayment.value.id, {
        preserveScroll: true,
        onSuccess: () => {
            showEditor.value = false;
        },
    });
};
</script>

<template>
    <Head title="Paiements" />

    <div class="med-page">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1
                    class="text-[2rem] leading-none font-bold tracking-tight text-[#111827] sm:text-[2.2rem] dark:text-slate-50"
                >
                    Paiements
                </h1>
                <div class="mt-3 h-1 w-20 rounded-full bg-brand" />
                <p class="mt-3 text-sm text-muted-foreground">
                    Suivez les règlements des consultations, les impayés et les
                    rapports imprimables.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button
                    variant="secondary"
                    class="bg-slate-200 text-slate-800 hover:bg-slate-300 dark:bg-slate-700 dark:text-slate-100"
                    @click="showAllDebts"
                >
                    <CircleDollarSign class="size-4" />
                    Dettes
                    <span
                        v-if="summary.outstanding > 0"
                        class="rounded-full bg-white/80 px-2 py-0.5 text-xs text-slate-900 tabular-nums"
                    >
                        {{ formatMoney(summary.outstanding) }}
                    </span>
                </Button>
                <Button as-child class="bg-emerald-600 hover:bg-emerald-700">
                    <a :href="reportUrl" target="_blank" rel="noopener">
                        <Printer class="size-4" />
                        Imprimer le rapport
                    </a>
                </Button>
            </div>
        </header>

        <section class="grid gap-4 md:grid-cols-3">
            <article class="med-panel p-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p
                            class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Honoraires du jour
                        </p>
                        <p class="mt-2 text-2xl font-bold tabular-nums">
                            {{ formatMoney(summary.today) }}
                        </p>
                    </div>
                    <span
                        class="flex size-11 items-center justify-center rounded-xl bg-brand-soft text-brand"
                    >
                        <Banknote class="size-5" />
                    </span>
                </div>
            </article>
            <article class="med-panel p-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p
                            class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Encaissé
                        </p>
                        <p
                            class="mt-2 text-2xl font-bold text-emerald-600 tabular-nums"
                        >
                            {{ formatMoney(summary.paid) }}
                        </p>
                    </div>
                    <span
                        class="flex size-11 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600"
                    >
                        <WalletCards class="size-5" />
                    </span>
                </div>
            </article>
            <article class="med-panel p-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p
                            class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            À encaisser
                        </p>
                        <p
                            class="mt-2 text-2xl font-bold text-amber-600 tabular-nums"
                        >
                            {{ formatMoney(summary.outstanding) }}
                        </p>
                    </div>
                    <span
                        class="flex size-11 items-center justify-center rounded-xl bg-amber-50 text-amber-600"
                    >
                        <CircleDollarSign class="size-5" />
                    </span>
                </div>
            </article>
        </section>

        <section class="med-panel overflow-hidden">
            <div class="bg-brand p-4 text-white">
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
                    <label class="grid gap-1.5">
                        <span class="text-xs font-semibold text-brand-soft"
                            >Du</span
                        >
                        <Input
                            v-model="localFilters.from"
                            type="date"
                            class="border-white/20 bg-white text-slate-900"
                        />
                    </label>
                    <label class="grid gap-1.5">
                        <span class="text-xs font-semibold text-brand-soft"
                            >Au</span
                        >
                        <Input
                            v-model="localFilters.to"
                            type="date"
                            class="border-white/20 bg-white text-slate-900"
                        />
                    </label>
                    <label class="grid gap-1.5">
                        <span class="text-xs font-semibold text-brand-soft"
                            >Utilisateur</span
                        >
                        <select
                            v-model="localFilters.user"
                            class="h-10 rounded-xl border border-white/30 bg-white px-3 text-sm text-slate-900 shadow-sm"
                        >
                            <option value="">Tous les utilisateurs</option>
                            <option
                                v-for="user in users"
                                :key="user.id"
                                :value="String(user.id)"
                            >
                                {{ user.name }}
                            </option>
                        </select>
                    </label>
                    <label class="grid gap-1.5">
                        <span class="text-xs font-semibold text-brand-soft"
                            >Statut</span
                        >
                        <select
                            v-model="localFilters.status"
                            class="h-10 rounded-xl border border-white/30 bg-white px-3 text-sm text-slate-900 shadow-sm"
                        >
                            <option value="all">
                                {{ paymentStatusLabel('all') }}
                            </option>
                            <option value="paid">
                                {{ paymentStatusLabel('paid') }}
                            </option>
                            <option value="unpaid">
                                {{ paymentStatusLabel('unpaid') }}
                            </option>
                            <option value="partial">Partiellement payé</option>
                            <option value="debt">Toutes les dettes</option>
                        </select>
                    </label>
                    <label class="grid gap-1.5">
                        <span class="text-xs font-semibold text-brand-soft"
                            >Mode de paiement</span
                        >
                        <select
                            v-model="localFilters.method"
                            class="h-10 rounded-xl border border-white/30 bg-white px-3 text-sm text-slate-900 shadow-sm"
                        >
                            <option value="">Tous les modes</option>
                            <option
                                v-for="method in methods"
                                :key="method"
                                :value="method"
                            >
                                {{ paymentMethodLabel(method) }}
                            </option>
                        </select>
                    </label>
                    <div class="flex items-end gap-2">
                        <Button
                            class="flex-1 bg-amber-500 text-slate-950 hover:bg-amber-400"
                            @click="applyFilters"
                        >
                            <Filter class="size-4" /> Appliquer
                        </Button>
                        <Button
                            size="icon"
                            variant="secondary"
                            aria-label="Réinitialiser les filtres"
                            title="Réinitialiser les filtres"
                            @click="resetFilters"
                        >
                            <RefreshCw class="size-4" />
                        </Button>
                    </div>
                </div>
            </div>

            <div class="p-4">
                <div class="mb-4 flex justify-end">
                    <div class="relative w-full max-w-sm">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            v-model="localFilters.search"
                            class="pl-9"
                            aria-label="Rechercher un paiement"
                            placeholder="Rechercher un patient, un numéro ou une prestation…"
                            @keyup.enter="applyFilters"
                        />
                    </div>
                </div>

                <div class="med-table-wrap">
                    <table class="med-table">
                        <thead>
                            <tr>
                                <th class="px-4 py-3 font-medium">Patient</th>
                                <th class="px-4 py-3 font-medium">
                                    N° de paiement
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    Utilisateur
                                </th>
                                <th class="px-4 py-3 font-medium">Mode</th>
                                <th class="px-4 py-3 font-medium">Montant</th>
                                <th class="px-4 py-3 font-medium">
                                    Prestation
                                </th>
                                <th class="px-4 py-3 font-medium">Date</th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="payment in payments.data"
                                :key="payment.id"
                                class="bg-background transition-colors hover:bg-muted/30"
                            >
                                <td class="px-4 py-3">
                                    <div
                                        class="flex min-w-48 items-center gap-3"
                                    >
                                        <span
                                            class="flex size-9 shrink-0 items-center justify-center rounded-full bg-amber-500 text-xs font-bold text-white"
                                        >
                                            {{ payment.initials }}
                                        </span>
                                        <span>
                                            <span
                                                class="block font-semibold text-foreground"
                                                >{{
                                                    payment.patient_name
                                                }}</span
                                            >
                                            <span
                                                class="text-xs text-muted-foreground"
                                                >{{
                                                    payment.patient_number ??
                                                    '—'
                                                }}</span
                                            >
                                        </span>
                                    </div>
                                </td>
                                <td
                                    class="px-4 py-3 font-mono text-muted-foreground"
                                >
                                    #{{ payment.id }}
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ payment.user_name ?? '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700"
                                    >
                                        {{ paymentMethodLabel(payment.method) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 tabular-nums">
                                    <div class="font-bold">
                                        {{ formatMoney(payment.amount) }}
                                    </div>
                                    <div
                                        class="mt-1 text-[11px] font-medium text-muted-foreground"
                                    >
                                        Versé {{ formatMoney(payment.paid) }}
                                        <template v-if="payment.adjustment > 0">
                                            · remise
                                            {{
                                                formatMoney(payment.adjustment)
                                            }}
                                        </template>
                                        <template
                                            v-if="payment.outstanding > 0"
                                        >
                                            · reste
                                            <span class="text-amber-700">{{
                                                formatMoney(payment.outstanding)
                                            }}</span>
                                        </template>
                                    </div>
                                    <span
                                        class="mt-1 inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                        :class="
                                            payment.status === 'paid'
                                                ? 'bg-emerald-50 text-emerald-700'
                                                : payment.status === 'partial'
                                                  ? 'bg-amber-50 text-amber-700'
                                                  : 'bg-slate-100 text-slate-700'
                                        "
                                    >
                                        {{
                                            payment.status === 'paid'
                                                ? 'Soldé'
                                                : payment.status === 'partial'
                                                  ? 'Paiement partiel'
                                                  : 'Dette'
                                        }}
                                    </span>
                                </td>
                                <td
                                    class="max-w-56 px-4 py-3 text-muted-foreground"
                                >
                                    <span class="line-clamp-2">{{
                                        payment.service
                                    }}</span>
                                </td>
                                <td
                                    class="px-4 py-3 whitespace-nowrap text-muted-foreground"
                                >
                                    {{
                                        paymentDateLabel(
                                            payment.date,
                                            payment.date_label,
                                        )
                                    }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-1">
                                        <Button
                                            v-if="canEdit"
                                            variant="ghost"
                                            size="icon"
                                            aria-label="Modifier le paiement"
                                            title="Modifier le paiement"
                                            @click="openEditor(payment)"
                                        >
                                            <Pencil class="size-4" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            as-child
                                            aria-label="Imprimer le reçu"
                                            title="Imprimer le reçu"
                                        >
                                            <a
                                                :href="
                                                    '/app/payments/' +
                                                    payment.id +
                                                    '/print'
                                                "
                                                target="_blank"
                                                rel="noopener"
                                            >
                                                <Printer class="size-4" />
                                            </a>
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            as-child
                                            aria-label="Ouvrir le dossier patient"
                                            title="Ouvrir le dossier patient"
                                        >
                                            <Link
                                                :href="
                                                    '/app/patients/' +
                                                    payment.patient_id
                                                "
                                            >
                                                <UserRound class="size-4" />
                                            </Link>
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!payments.data.length">
                                <td colspan="8" class="px-6 py-14 text-center">
                                    <FileText
                                        class="mx-auto size-9 text-muted-foreground/40"
                                    />
                                    <p class="mt-3 font-semibold">
                                        Aucun paiement correspondant
                                    </p>
                                    <p
                                        class="mt-1 text-sm text-muted-foreground"
                                    >
                                        Modifiez les filtres ou enregistrez un
                                        paiement depuis une consultation.
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <footer
                    class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <p class="text-sm text-muted-foreground">
                        Affichage de {{ payments.from ?? 0 }} à
                        {{ payments.to ?? 0 }} sur
                        {{ payments.total }} paiements
                    </p>
                    <div class="flex flex-wrap gap-1">
                        <template
                            v-for="link in payments.links"
                            :key="link.label"
                        >
                            <Button
                                v-if="link.url"
                                as-child
                                size="sm"
                                :variant="link.active ? 'default' : 'outline'"
                            >
                                <Link :href="link.url" preserve-scroll>
                                    <span
                                        v-html="
                                            paymentPaginationLabel(link.label)
                                        "
                                    />
                                </Link>
                            </Button>
                            <Button v-else size="sm" variant="outline" disabled>
                                <span
                                    v-html="paymentPaginationLabel(link.label)"
                                />
                            </Button>
                        </template>
                    </div>
                </footer>
            </div>
        </section>

        <a
            :href="reportUrl"
            target="_blank"
            rel="noopener"
            class="fixed right-5 bottom-5 z-30 flex size-14 items-center justify-center rounded-full bg-emerald-600 text-white shadow-lg shadow-emerald-600/25 transition hover:-translate-y-0.5 hover:bg-emerald-700"
            aria-label="Imprimer les paiements filtrés"
            title="Imprimer les paiements filtrés"
        >
            <Printer class="size-6" />
        </a>
    </div>

    <Dialog v-model:open="showEditor">
        <DialogContent class="overflow-hidden p-0 sm:max-w-2xl">
            <div class="bg-brand px-6 py-5 text-brand-foreground">
                <DialogHeader>
                    <DialogTitle class="text-xl text-white"
                        >Prestation et montant</DialogTitle
                    >
                    <DialogDescription class="text-brand-foreground/80">
                        {{ selectedPayment?.patient_name }} · Paiement n°{{
                            selectedPayment?.id
                        }}
                    </DialogDescription>
                </DialogHeader>
            </div>

            <form class="grid gap-5 p-6" @submit.prevent="savePayment">
                <div
                    class="grid gap-3 rounded-xl border bg-muted/25 p-4 sm:grid-cols-3"
                >
                    <div>
                        <p class="text-xs text-muted-foreground">Facturé</p>
                        <p class="font-bold tabular-nums">
                            {{ formatMoney(selectedPayment?.amount ?? 0) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Déjà versé</p>
                        <p class="font-bold text-brand tabular-nums">
                            {{ formatMoney(selectedPayment?.paid ?? 0) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">
                            Reste après versement
                        </p>
                        <p class="font-bold text-amber-700 tabular-nums">
                            {{ formatMoney(projectedOutstanding) }}
                        </p>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2 sm:col-span-2">
                        <Label>Prestation</Label>
                        <Select
                            :model-value="paymentForm.service || undefined"
                            @update:model-value="
                                (value) => chooseService(value as string)
                            "
                        >
                            <SelectTrigger class="w-full">
                                <SelectValue
                                    placeholder="Choisir une prestation"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="service in services"
                                    :key="service.label"
                                    :value="service.label"
                                >
                                    {{ service.label }} ·
                                    {{ formatMoney(service.amount) }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <Input
                            v-model="paymentForm.service"
                            placeholder="Ou saisir une prestation personnalisée"
                        />
                        <InputError :message="paymentForm.errors.service" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="payment-amount">Prix total</Label>
                        <Input
                            id="payment-amount"
                            v-model="paymentForm.amount"
                            type="number"
                            min="0"
                            step="0.01"
                        />
                        <InputError :message="paymentForm.errors.amount" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="payment-paid-today">
                            Montant versé aujourd’hui
                        </Label>
                        <Input
                            id="payment-paid-today"
                            v-model="paymentForm.paid_today"
                            type="number"
                            min="0"
                            :max="selectedPayment?.outstanding ?? undefined"
                            step="0.01"
                        />
                        <InputError :message="paymentForm.errors.paid_today" />
                    </div>
                    <div class="grid gap-2">
                        <Label>Mode de paiement</Label>
                        <Select
                            :model-value="paymentForm.method || undefined"
                            @update:model-value="
                                (value) =>
                                    (paymentForm.method = value as string)
                            "
                        >
                            <SelectTrigger class="w-full">
                                <SelectValue
                                    placeholder="Choisir un mode de paiement"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="method in methods"
                                    :key="method"
                                    :value="method"
                                >
                                    {{ paymentMethodLabel(method) }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="paymentForm.errors.method" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label>Traitement du reste</Label>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label
                            class="flex cursor-pointer items-start gap-3 rounded-xl border p-3"
                            :class="
                                paymentForm.settlement === 'debt'
                                    ? 'border-brand bg-brand-soft'
                                    : 'border-border'
                            "
                        >
                            <input
                                v-model="paymentForm.settlement"
                                type="radio"
                                value="debt"
                                class="mt-1 accent-brand"
                            />
                            <span>
                                <span class="block text-sm font-semibold">
                                    Conserver comme dette
                                </span>
                                <span class="text-xs text-muted-foreground">
                                    Le solde restera à payer.
                                </span>
                            </span>
                        </label>
                        <label
                            class="flex cursor-pointer items-start gap-3 rounded-xl border p-3"
                            :class="
                                paymentForm.settlement === 'settled'
                                    ? 'border-brand bg-brand-soft'
                                    : 'border-border'
                            "
                        >
                            <input
                                v-model="paymentForm.settlement"
                                type="radio"
                                value="settled"
                                class="mt-1 accent-brand"
                            />
                            <span>
                                <span class="block text-sm font-semibold">
                                    Accepter moins et solder
                                </span>
                                <span class="text-xs text-muted-foreground">
                                    Le reliquat devient une remise documentée.
                                </span>
                            </span>
                        </label>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="payment-notes">
                        Note
                        <span
                            v-if="
                                paymentForm.settlement === 'settled' &&
                                projectedOutstanding > 0
                            "
                            class="text-destructive"
                        >
                            (obligatoire pour solder avec une remise)
                        </span>
                    </Label>
                    <Textarea
                        id="payment-notes"
                        v-model="paymentForm.notes"
                        placeholder="Motif de remise, accord du patient ou détail du versement…"
                    />
                    <InputError :message="paymentForm.errors.notes" />
                </div>

                <details
                    v-if="selectedPayment?.installments.length"
                    class="rounded-xl border p-3"
                >
                    <summary class="cursor-pointer text-sm font-semibold">
                        {{ selectedPayment.installments.length }} versement(s)
                        déjà enregistré(s)
                    </summary>
                    <div class="mt-3 space-y-2">
                        <div
                            v-for="installment in selectedPayment.installments"
                            :key="installment.id"
                            class="flex flex-wrap justify-between gap-2 rounded-lg bg-muted/40 px-3 py-2 text-sm"
                        >
                            <span>{{
                                paymentDateLabel(installment.received_at)
                            }}</span>
                            <strong>{{
                                formatMoney(installment.amount)
                            }}</strong>
                            <span class="text-muted-foreground">
                                {{ installment.method || '—' }} ·
                                {{ installment.received_by || '—' }}
                            </span>
                        </div>
                    </div>
                </details>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="showEditor = false"
                    >
                        Annuler
                    </Button>
                    <Button type="submit" :disabled="paymentForm.processing">
                        <Banknote class="size-4" />
                        Enregistrer le paiement
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
