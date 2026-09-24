<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ArrowRight, CalendarClock, Search, UserRound } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';

type PatientHit = {
    id: number;
    name: string;
    number: string | null;
    phone: string | null;
    age: number | null;
    next_appointment: string | null;
};

type Item =
    | { kind: 'patient'; key: string; href: string; patient: PatientHit }
    | { kind: 'page'; key: string; href: string; label: string };

const page = usePage();
const open = ref(false);
const query = ref('');
const patients = ref<PatientHit[]>([]);
const active = ref(0);
const input = ref<HTMLInputElement | null>(null);
let timer: number | undefined;
let controller: AbortController | undefined;

const permissions = computed<string[]>(
    () => page.props.auth.user?.permissions ?? [],
);

const pages = computed(() =>
    [
        {
            label: 'Nouveau patient',
            href: '/app/patients/create',
            permission: 'patients.create',
        },
        {
            label: 'Patients',
            href: '/app/patients',
            permission: 'patients.view',
        },
        {
            label: 'Rendez-vous',
            href: '/app/appointments',
            permission: 'appointments.view',
        },
        {
            label: 'Relances (WhatsApp / SMS)',
            href: '/app/reminders',
            permission: 'appointments.view',
        },
        {
            label: 'Consultations du jour',
            href: '/app/consultations',
            permission: 'consultations.view',
        },
        {
            label: 'Journal des paiements',
            href: '/app/payments',
            permission: 'payments.view',
        },
        {
            label: 'Dettes patients',
            href: '/app/payments?status=debt',
            permission: 'payments.view',
        },
        {
            label: 'Analyse financière',
            href: '/app/payments/analytics',
            permission: 'payments.view',
        },
        {
            label: 'Charges du cabinet',
            href: '/app/expenses',
            permission: 'reports.view',
        },
        {
            label: 'Statistiques médicales',
            href: '/app/statistics',
            permission: 'reports.view',
        },
        {
            label: 'Salle d’attente (écran)',
            href: '/app/waiting-room',
            permission: 'appointments.view',
        },
        {
            label: 'Journal d’audit',
            href: '/app/audit-logs',
            permission: 'audit-logs.view',
        },
    ].filter((item) => permissions.value.includes(item.permission)),
);

const items = computed<Item[]>(() => {
    const term = query.value.trim().toLocaleLowerCase('fr');
    const pageItems: Item[] = pages.value
        .filter(
            (item) =>
                term === '' ||
                item.label.toLocaleLowerCase('fr').includes(term),
        )
        .map((item) => ({
            kind: 'page',
            key: `page:${item.href}`,
            href: item.href,
            label: item.label,
        }));

    return [
        ...patients.value.map((patient): Item => ({
            kind: 'patient',
            key: `patient:${patient.id}`,
            href: `/app/patients/${patient.id}`,
            patient,
        })),
        ...pageItems,
    ];
});

const search = async (term: string) => {
    controller?.abort();

    if (term.trim().length < 2) {
        patients.value = [];

        return;
    }

    controller = new AbortController();

    try {
        const response = await fetch(
            `/app/search?q=${encodeURIComponent(term.trim())}`,
            {
                // Marked as AJAX so Laravel does not remember it as the
                // « previous page » that back() redirects to.
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: controller.signal,
            },
        );
        patients.value = (
            (await response.json()) as { patients: PatientHit[] }
        ).patients;
        active.value = 0;
    } catch {
        // A newer keystroke aborted this request.
    }
};

watch(query, (term) => {
    active.value = 0;
    window.clearTimeout(timer);
    timer = window.setTimeout(() => search(term), 180);
});

const show = async () => {
    open.value = true;
    await nextTick();
    input.value?.focus();
};

const go = (item: Item | undefined) => {
    if (!item) {
        return;
    }

    open.value = false;
    query.value = '';
    patients.value = [];
    router.visit(item.href);
};

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        active.value = Math.min(active.value + 1, items.value.length - 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = Math.max(active.value - 1, 0);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        go(items.value[active.value]);
    }
};

const onGlobalKey = (event: KeyboardEvent) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        void show();
    }
};

onMounted(() => window.addEventListener('keydown', onGlobalKey));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onGlobalKey);
    window.clearTimeout(timer);
    controller?.abort();
});
</script>

<template>
    <button
        type="button"
        class="hidden h-10 shrink-0 items-center gap-2 rounded-full border bg-background px-3 text-sm text-muted-foreground shadow-sm transition hover:text-foreground md:inline-flex"
        aria-label="Rechercher un patient ou une page (Ctrl+K)"
        title="Rechercher (Ctrl+K)"
        @click="show"
    >
        <Search class="size-4" />
        <span class="hidden 2xl:inline">Rechercher…</span>
        <kbd
            class="hidden rounded border bg-muted px-1.5 text-[10px] font-semibold 2xl:inline"
            >Ctrl K</kbd
        >
    </button>

    <Dialog v-model:open="open">
        <DialogContent class="top-[15%] translate-y-0 gap-0 p-0 sm:max-w-xl">
            <DialogTitle class="sr-only">Recherche</DialogTitle>
            <DialogDescription class="sr-only">
                Rechercher un patient par nom, numéro de dossier ou téléphone,
                ou ouvrir une page.
            </DialogDescription>
            <div class="flex items-center gap-2 border-b px-4">
                <Search class="size-4 text-muted-foreground" />
                <input
                    ref="input"
                    v-model="query"
                    class="h-12 flex-1 bg-transparent text-sm outline-none"
                    placeholder="Nom, n° de dossier, téléphone… ou page"
                    aria-label="Rechercher"
                    @keydown="onKeydown"
                />
            </div>
            <ul class="max-h-96 overflow-y-auto p-2" role="listbox">
                <li
                    v-for="(item, index) in items"
                    :key="item.key"
                    role="option"
                    :aria-selected="index === active"
                >
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm"
                        :class="index === active ? 'bg-accent' : ''"
                        @mouseenter="active = index"
                        @click="go(item)"
                    >
                        <template v-if="item.kind === 'patient'">
                            <UserRound class="size-4 shrink-0 text-brand" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-semibold">{{
                                    item.patient.name
                                }}</span>
                                <span
                                    class="block truncate text-xs text-muted-foreground"
                                >
                                    {{ item.patient.number }}
                                    <template v-if="item.patient.age !== null">
                                        · {{ item.patient.age }} ans</template
                                    >
                                    <template v-if="item.patient.phone">
                                        · {{ item.patient.phone }}</template
                                    >
                                </span>
                            </span>
                            <span
                                v-if="item.patient.next_appointment"
                                class="inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground"
                            >
                                <CalendarClock class="size-3.5" />
                                {{ item.patient.next_appointment }}
                            </span>
                        </template>
                        <template v-else>
                            <ArrowRight
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            <span class="flex-1">{{ item.label }}</span>
                        </template>
                    </button>
                </li>
                <li
                    v-if="!items.length"
                    class="px-3 py-6 text-center text-sm text-muted-foreground"
                >
                    Aucun résultat.
                </li>
            </ul>
        </DialogContent>
    </Dialog>
</template>
