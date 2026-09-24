<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    CalendarSync,
    Download,
    Filter,
    Pencil,
    Plus,
    ReceiptText,
    RefreshCw,
    Repeat,
    Search,
    Trash2,
} from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import HBarChart from '@/components/charts/HBarChart.vue';
import InputError from '@/components/InputError.vue';
import PaymentsTabs from '@/components/payments/PaymentsTabs.vue';
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
import { Textarea } from '@/components/ui/textarea';
import {
    createFrDzMoneyFormatter,
    paymentMethodLabel,
    paymentPaginationLabel,
} from '@/pages/payments/display';

type Expense = {
    id: string;
    category: string;
    category_label: string;
    label: string;
    amount: number;
    spent_on: string;
    method: string | null;
    supplier: string | null;
    notes: string | null;
    is_recurring: boolean;
    created_by: string | null;
};

const props = defineProps<{
    expenses: {
        data: Expense[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { from: string; to: string; category: string; search: string };
    totals: {
        amount: number;
        count: number;
        byCategory: {
            category: string;
            label: string;
            amount: number;
            count: number;
        }[];
    };
    categories: { value: string; label: string }[];
    methods: string[];
    currency: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Paiements', href: '/app/payments' },
            { title: 'Charges', href: '/app/expenses' },
        ],
    },
});

const formatMoney = createFrDzMoneyFormatter(props.currency);
const localFilters = reactive({ ...props.filters });

const today = (): string => {
    const now = new Date();
    const pad = (value: number) => String(value).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
};

const applyFilters = () => {
    router.get(
        '/app/expenses',
        {
            from: localFilters.from,
            to: localFilters.to,
            category: localFilters.category || undefined,
            search: localFilters.search || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const resetFilters = () => {
    router.get('/app/expenses', {}, { preserveScroll: true });
};

const exportUrl = computed(() => {
    const params = new URLSearchParams();

    Object.entries(localFilters).forEach(([key, value]) => {
        if (value) {
            params.set(key, value);
        }
    });

    return `/app/expenses/export?${params.toString()}`;
});

// The month shown by the filter (its first day) is where recurring
// charges are copied to.
const targetMonth = computed(() => props.filters.from.slice(0, 7));
const targetMonthLabel = computed(() =>
    new Intl.DateTimeFormat('fr-DZ', { month: 'long', year: 'numeric' }).format(
        new Date(`${targetMonth.value}-01T00:00:00`),
    ),
);

const copyRecurring = () => {
    router.post(
        '/app/expenses/recurring',
        { month: targetMonth.value },
        { preserveScroll: true },
    );
};

const showEditor = ref(false);
const editing = ref<Expense | null>(null);
const form = useForm({
    category: 'rent',
    label: '',
    amount: '',
    spent_on: today(),
    method: '',
    supplier: '',
    notes: '',
    is_recurring: false,
});

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.spent_on = today();
    form.clearErrors();
    showEditor.value = true;
};

const openEdit = (expense: Expense) => {
    editing.value = expense;
    form.category = expense.category;
    form.label = expense.label;
    form.amount = String(expense.amount);
    form.spent_on = expense.spent_on;
    form.method = expense.method ?? '';
    form.supplier = expense.supplier ?? '';
    form.notes = expense.notes ?? '';
    form.is_recurring = expense.is_recurring;
    form.clearErrors();
    showEditor.value = true;
};

const save = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            showEditor.value = false;
        },
    };

    if (editing.value) {
        form.patch(`/app/expenses/${editing.value.id}`, options);
    } else {
        form.post('/app/expenses', options);
    }
};

const deleting = ref<Expense | null>(null);
const confirmDelete = () => {
    if (!deleting.value) {
        return;
    }

    router.delete(`/app/expenses/${deleting.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = null;
        },
    });
};

const dateLabel = (date: string): string =>
    new Intl.DateTimeFormat('fr-DZ', { dateStyle: 'medium' }).format(
        new Date(`${date}T00:00:00`),
    );

const categoryBars = computed(() =>
    props.totals.byCategory.map((row) => ({
        label: `${row.label} (${row.count})`,
        value: row.amount,
    })),
);
</script>

<template>
    <Head title="Charges du cabinet" />

    <div class="med-page">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1
                    class="text-[2rem] leading-none font-bold tracking-tight text-[#111827] sm:text-[2.2rem] dark:text-slate-50"
                >
                    Charges du cabinet
                </h1>
                <div class="mt-3 h-1 w-20 rounded-full bg-brand" />
                <p class="mt-3 text-sm text-muted-foreground">
                    Loyer, salaires, consommables… pour connaître le bénéfice
                    réel du cabinet.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button variant="outline" @click="copyRecurring">
                    <CalendarSync class="size-4" />
                    Reporter les charges récurrentes ({{ targetMonthLabel }})
                </Button>
                <Button variant="outline" as-child>
                    <a :href="exportUrl">
                        <Download class="size-4" />
                        Exporter (CSV)
                    </a>
                </Button>
                <Button @click="openCreate">
                    <Plus class="size-4" />
                    Nouvelle charge
                </Button>
            </div>
        </header>

        <PaymentsTabs active="expenses" />

        <section
            class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)]"
        >
            <div class="grid gap-4">
                <article class="med-panel p-5">
                    <p
                        class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Total des charges
                    </p>
                    <p
                        class="mt-2 text-3xl font-bold text-rose-700 tabular-nums dark:text-rose-400"
                    >
                        {{ formatMoney(totals.amount) }}
                    </p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ totals.count }} dépense(s) du
                        {{ dateLabel(filters.from) }} au
                        {{ dateLabel(filters.to) }}
                    </p>
                    <Link
                        href="/app/payments/analytics"
                        class="mt-3 inline-block text-sm font-semibold text-brand hover:underline"
                    >
                        Voir le bénéfice net →
                    </Link>
                </article>
            </div>
            <article class="med-panel p-5">
                <p class="text-sm font-bold">Répartition par catégorie</p>
                <div class="mt-4">
                    <HBarChart
                        v-if="categoryBars.length"
                        :data="categoryBars"
                        :format-value="formatMoney"
                    />
                    <p
                        v-else
                        class="py-6 text-center text-sm text-muted-foreground"
                    >
                        Aucune charge sur la période.
                    </p>
                </div>
            </article>
        </section>

        <section class="med-panel overflow-hidden">
            <div class="bg-brand p-4 text-white">
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                    <label class="grid gap-1.5">
                        <span class="text-xs font-semibold text-white/80"
                            >Du</span
                        >
                        <Input
                            v-model="localFilters.from"
                            type="date"
                            class="border-white/20 bg-white text-slate-900"
                        />
                    </label>
                    <label class="grid gap-1.5">
                        <span class="text-xs font-semibold text-white/80"
                            >Au</span
                        >
                        <Input
                            v-model="localFilters.to"
                            type="date"
                            class="border-white/20 bg-white text-slate-900"
                        />
                    </label>
                    <label class="grid gap-1.5">
                        <span class="text-xs font-semibold text-white/80"
                            >Catégorie</span
                        >
                        <select
                            v-model="localFilters.category"
                            class="h-10 rounded-xl border border-white/30 bg-white px-3 text-sm text-slate-900 shadow-sm"
                        >
                            <option value="">Toutes les catégories</option>
                            <option
                                v-for="category in categories"
                                :key="category.value"
                                :value="category.value"
                            >
                                {{ category.label }}
                            </option>
                        </select>
                    </label>
                    <label class="grid gap-1.5">
                        <span class="text-xs font-semibold text-white/80"
                            >Recherche</span
                        >
                        <div class="relative">
                            <Search
                                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400"
                            />
                            <Input
                                v-model="localFilters.search"
                                class="border-white/20 bg-white pl-9 text-slate-900"
                                placeholder="Libellé, fournisseur…"
                                @keyup.enter="applyFilters"
                            />
                        </div>
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
                <div class="med-table-wrap">
                    <table class="med-table">
                        <thead>
                            <tr>
                                <th class="px-4 py-3 font-medium">Date</th>
                                <th class="px-4 py-3 font-medium">Catégorie</th>
                                <th class="px-4 py-3 font-medium">Libellé</th>
                                <th class="px-4 py-3 font-medium">Mode</th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Montant
                                </th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="expense in expenses.data"
                                :key="expense.id"
                                class="bg-background"
                            >
                                <td
                                    class="px-4 py-3 whitespace-nowrap text-muted-foreground"
                                >
                                    {{ dateLabel(expense.spent_on) }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="rounded-full bg-muted px-2.5 py-1 text-xs font-semibold"
                                        >{{ expense.category_label }}</span
                                    >
                                </td>
                                <td class="px-4 py-3">
                                    <span class="font-semibold">{{
                                        expense.label
                                    }}</span>
                                    <span
                                        v-if="expense.is_recurring"
                                        class="ml-2 inline-flex items-center gap-1 rounded-full bg-brand-soft px-2 py-0.5 text-[11px] font-semibold text-brand"
                                        title="Charge récurrente chaque mois"
                                    >
                                        <Repeat class="size-3" /> mensuelle
                                    </span>
                                    <span
                                        v-if="expense.supplier || expense.notes"
                                        class="block text-xs text-muted-foreground"
                                    >
                                        {{
                                            [expense.supplier, expense.notes]
                                                .filter(Boolean)
                                                .join(' · ')
                                        }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{
                                        expense.method
                                            ? paymentMethodLabel(expense.method)
                                            : '—'
                                    }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right font-bold text-rose-700 tabular-nums dark:text-rose-400"
                                >
                                    {{ formatMoney(expense.amount) }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-1">
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label="Modifier la charge"
                                            title="Modifier la charge"
                                            @click="openEdit(expense)"
                                        >
                                            <Pencil class="size-4" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label="Supprimer la charge"
                                            title="Supprimer la charge"
                                            @click="deleting = expense"
                                        >
                                            <Trash2
                                                class="size-4 text-rose-600"
                                            />
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!expenses.data.length">
                                <td colspan="6" class="px-6 py-14 text-center">
                                    <ReceiptText
                                        class="mx-auto size-9 text-muted-foreground/40"
                                    />
                                    <p class="mt-3 font-semibold">
                                        Aucune charge sur cette période
                                    </p>
                                    <p
                                        class="mt-1 text-sm text-muted-foreground"
                                    >
                                        Ajoutez le loyer, les salaires ou les
                                        achats pour suivre le bénéfice réel.
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <footer
                    v-if="expenses.total > 0"
                    class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <p class="text-sm text-muted-foreground">
                        Affichage de {{ expenses.from ?? 0 }} à
                        {{ expenses.to ?? 0 }} sur {{ expenses.total }} charges
                    </p>
                    <div class="flex flex-wrap gap-1">
                        <template
                            v-for="link in expenses.links"
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
                        </template>
                    </div>
                </footer>
            </div>
        </section>
    </div>

    <Dialog v-model:open="showEditor">
        <DialogContent class="sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>
                    {{ editing ? 'Modifier la charge' : 'Nouvelle charge' }}
                </DialogTitle>
                <DialogDescription>
                    Une dépense du cabinet, comptée dans le bénéfice net.
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="save">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="expense-category">Catégorie</Label>
                        <select
                            id="expense-category"
                            v-model="form.category"
                            class="h-10 rounded-xl border border-input bg-background px-3 text-sm shadow-sm"
                        >
                            <option
                                v-for="category in categories"
                                :key="category.value"
                                :value="category.value"
                            >
                                {{ category.label }}
                            </option>
                        </select>
                        <InputError :message="form.errors.category" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="expense-date">Date</Label>
                        <Input
                            id="expense-date"
                            v-model="form.spent_on"
                            type="date"
                        />
                        <InputError :message="form.errors.spent_on" />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="expense-label">Libellé</Label>
                        <Input
                            id="expense-label"
                            v-model="form.label"
                            placeholder="Ex. Loyer du local, salaire de la secrétaire…"
                        />
                        <InputError :message="form.errors.label" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="expense-amount">Montant</Label>
                        <Input
                            id="expense-amount"
                            v-model="form.amount"
                            type="number"
                            min="0.01"
                            step="0.01"
                        />
                        <InputError :message="form.errors.amount" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="expense-method">Mode de paiement</Label>
                        <select
                            id="expense-method"
                            v-model="form.method"
                            class="h-10 rounded-xl border border-input bg-background px-3 text-sm shadow-sm"
                        >
                            <option value="">Non renseigné</option>
                            <option
                                v-for="method in methods"
                                :key="method"
                                :value="method"
                            >
                                {{ paymentMethodLabel(method) }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="expense-supplier">
                            Fournisseur / bénéficiaire
                        </Label>
                        <Input id="expense-supplier" v-model="form.supplier" />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="expense-notes">Note</Label>
                        <Textarea id="expense-notes" v-model="form.notes" />
                    </div>
                </div>

                <label
                    class="flex cursor-pointer items-start gap-3 rounded-xl border p-3"
                >
                    <input
                        v-model="form.is_recurring"
                        type="checkbox"
                        class="mt-1 accent-brand"
                    />
                    <span>
                        <span class="block text-sm font-semibold">
                            Charge mensuelle récurrente
                        </span>
                        <span class="text-xs text-muted-foreground">
                            Loyer, salaires, abonnements : reportée chaque mois
                            en un clic.
                        </span>
                    </span>
                </label>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="showEditor = false"
                    >
                        Annuler
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        Enregistrer
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="deleting !== null"
        @update:open="(open) => !open && (deleting = null)"
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Supprimer cette charge ?</DialogTitle>
                <DialogDescription>
                    {{ deleting?.label }} ·
                    {{ formatMoney(deleting?.amount ?? 0) }}. La suppression est
                    enregistrée dans le journal d’audit.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="deleting = null">
                    Annuler
                </Button>
                <Button variant="destructive" @click="confirmDelete">
                    <Trash2 class="size-4" />
                    Supprimer
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
