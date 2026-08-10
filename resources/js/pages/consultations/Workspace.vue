<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    Activity,
    ArrowLeft,
    CalendarDays,
    ChartColumn,
    Check,
    Cloud,
    CloudOff,
    Clock,
    FileText,
    FlaskConical,
    FolderOpen,
    Mail,
    Pill,
    Plus,
    Save,
    Stethoscope,
    TriangleAlert,
    Wallet,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Component } from 'vue';
import BilansPanel from '@/components/consultations/BilansPanel.vue';
import CourbesPanel from '@/components/consultations/CourbesPanel.vue';
import CourriersPanel from '@/components/consultations/CourriersPanel.vue';
import DocumentsPanel from '@/components/consultations/DocumentsPanel.vue';
import OrdonnancesPanel from '@/components/consultations/OrdonnancesPanel.vue';
import RendezVousPanel from '@/components/consultations/RendezVousPanel.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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
import type {
    ClinicalDocument,
    ClinicalDocumentTemplate,
    ClinicalOnlyOfficeSettings,
    DocumentBranding,
    ExamOption,
    MedicationOption,
    UploadedConsultationFile,
} from '@/types/clinicalDocuments';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Consultation', href: '/app/consultations' },
            { title: 'Espace de consultation', href: '#' },
        ],
    },
});

type Option = { value: string; label: string };
type AppointmentRow = {
    id: number;
    date: string | null;
    time: string | null;
    status: string;
    reason: string | null;
};
type Measurement = {
    id: number;
    measured_at: string | null;
    weight_kg: string | number | null;
    height_cm: string | number | null;
    bmi: string | number | null;
    waist_cm: string | number | null;
    head_cm: string | number | null;
    notes: string | null;
};
type PrescriptionItem = {
    medication: string;
    dosage: string;
    duration: string;
    instructions: string;
};
type PrescriptionRow = {
    id: number;
    document_id: number | null;
    template_id: string | null;
    prescribed_at: string | null;
    items: PrescriptionItem[];
    notes: string | null;
};

type PatientInfo = {
    id: number;
    patient_number: string;
    first_name: string;
    last_name: string;
    full_name: string;
    date_of_birth: string | null;
    gender: string | null;
    marital_status: string | null;
    profession: string | null;
    smoking_status: string | null;
    referred_by: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    city: string | null;
    blood_group: string | null;
    allergies: string | null;
    antecedents_medical: string | null;
    antecedents_surgical: string | null;
    antecedents_family: string | null;
    antecedents_gyneco: string | null;
    antecedents_other: string | null;
};

type ConsultationData = {
    id: number;
    status: string;
    consulted_at: string | null;
    completed_at: string | null;
    motif: string | null;
    examens: string | null;
    diagnostic: string | null;
    traitement: string | null;
    notes: string | null;
    weight_kg: string | number | null;
    height_cm: string | number | null;
    temperature_c: string | number | null;
    blood_pressure: string | null;
    payment_amount: number | null;
    payment_paid: number;
    payment_adjustment: number;
    payment_outstanding: number;
    payment_status: 'paid' | 'partial' | 'unpaid';
    payment_method: string | null;
    payment_service: string | null;
    payment_notes: string | null;
    is_paid: boolean;
    payments: {
        id: string;
        amount: number;
        method: string | null;
        notes: string | null;
        received_at: string | null;
        received_by: string | null;
    }[];
};

const props = defineProps<{
    consultation: ConsultationData;
    patient: PatientInfo;
    patientDebt: {
        total: number;
        consultations: {
            id: number;
            date: string | null;
            service: string;
            charged: number;
            paid: number;
            outstanding: number;
        }[];
    };
    options: {
        genders: Option[];
        bloodGroups: Option[];
        maritalStatuses: Option[];
        smokingStatuses: Option[];
        paymentMethods: string[];
    };
    history: {
        id: number;
        consulted_at: string | null;
        motif: string | null;
        diagnostic: string | null;
        status: string;
    }[];
    upcoming: AppointmentRow[];
    past: AppointmentRow[];
    measurements: Measurement[];
    prescriptions: PrescriptionRow[];
    documents: ClinicalDocument[];
    uploadedFiles: UploadedConsultationFile[];
    documentTemplates: ClinicalDocumentTemplate[];
    onlyoffice: ClinicalOnlyOfficeSettings;
    prestations: { label: string; amount: number | null }[];
    medications: MedicationOption[];
    exams: ExamOption[];
    bilanCategories: {
        key: string;
        label: string;
        hint: string | null;
    }[];
    cabinet: DocumentBranding;
    stats: { consultations: number; appointments: number };
    canEdit: boolean;
    canCollectPayment: boolean;
}>();

type SectionKey =
    | 'dossier'
    | 'historique'
    | 'rendezvous'
    | 'courbes'
    | 'caisse'
    | 'ordonnances'
    | 'bilans'
    | 'courriers'
    | 'documents'
    | 'analytics';

const sections: {
    key: SectionKey;
    label: string;
    sub: string;
    icon: Component;
}[] = [
    {
        key: 'dossier',
        label: 'Dossier',
        sub: 'Fiche patient',
        icon: FolderOpen,
    },
    {
        key: 'historique',
        label: 'Historique',
        sub: 'Antécédents & historique',
        icon: Clock,
    },
    {
        key: 'rendezvous',
        label: 'Rendez-vous',
        sub: 'Créneaux & agenda',
        icon: CalendarDays,
    },
    { key: 'courbes', label: 'Courbes', sub: 'de croissance', icon: Activity },
    { key: 'caisse', label: 'Caisse', sub: 'Gestion financière', icon: Wallet },
    {
        key: 'ordonnances',
        label: 'Ordonnances',
        sub: 'Historique & impression',
        icon: Pill,
    },
    {
        key: 'bilans',
        label: 'Bilans',
        sub: 'Examens & résultats',
        icon: FlaskConical,
    },
    {
        key: 'courriers',
        label: 'Courriers',
        sub: 'Documents & certificats',
        icon: Mail,
    },
    {
        key: 'documents',
        label: 'Documents',
        sub: 'Fichiers importés',
        icon: FileText,
    },
    {
        key: 'analytics',
        label: 'Synthèse',
        sub: 'Synthèse du dossier',
        icon: ChartColumn,
    },
];

const sectionOrder: SectionKey[] = [
    'dossier',
    'historique',
    'courbes',
    'ordonnances',
    'bilans',
    'courriers',
    'documents',
    'analytics',
    'caisse',
    'rendezvous',
];

const orderedSections = computed(() =>
    sectionOrder
        .map((key) => sections.find((section) => section.key === key))
        .filter(
            (section): section is (typeof sections)[number] =>
                section !== undefined,
        ),
);

const active = ref<SectionKey>('dossier');
const showQuickActions = ref(false);
const isOnline = ref(
    typeof navigator === 'undefined' ? true : navigator.onLine,
);
const offlineDraftSavedAt = ref<string | null>(null);
const offlineDraftKey = `clickdz:consultation:${props.consultation.id}:draft`;
const dossierTab = ref<'etat_civil' | 'antecedents'>('etat_civil');
const ordonnanceTemplates = computed(() =>
    props.documentTemplates.filter(
        (template) =>
            template.category === 'ordonnance' ||
            template.category === 'general',
    ),
);
const ordonnanceTemplateId = ref<string | null>(null);
const ordonnanceDate = ref(new Date().toISOString().slice(0, 10));
const ordonnanceShowDate = ref(true);

watch(
    ordonnanceTemplates,
    (templates) => {
        if (
            !templates.some(
                (template) =>
                    `${template.source}:${template.key}` ===
                    ordonnanceTemplateId.value,
            )
        ) {
            const first = templates[0];
            ordonnanceTemplateId.value = first
                ? `${first.source}:${first.key}`
                : null;
        }
    },
    { immediate: true },
);

const patientForm = useForm({
    first_name: props.patient.first_name ?? '',
    last_name: props.patient.last_name ?? '',
    date_of_birth: props.patient.date_of_birth ?? '',
    gender: props.patient.gender ?? '',
    marital_status: props.patient.marital_status ?? '',
    profession: props.patient.profession ?? '',
    smoking_status: props.patient.smoking_status ?? '',
    referred_by: props.patient.referred_by ?? '',
    phone: props.patient.phone ?? '',
    email: props.patient.email ?? '',
    address: props.patient.address ?? '',
    city: props.patient.city ?? '',
    blood_group: props.patient.blood_group ?? '',
    allergies: props.patient.allergies ?? '',
    antecedents_medical: props.patient.antecedents_medical ?? '',
    antecedents_surgical: props.patient.antecedents_surgical ?? '',
    antecedents_family: props.patient.antecedents_family ?? '',
    antecedents_gyneco: props.patient.antecedents_gyneco ?? '',
    antecedents_other: props.patient.antecedents_other ?? '',
});

const consultationForm = useForm({
    motif: props.consultation.motif ?? '',
    examens: props.consultation.examens ?? '',
    diagnostic: props.consultation.diagnostic ?? '',
    traitement: props.consultation.traitement ?? '',
    notes: props.consultation.notes ?? '',
    weight_kg:
        props.consultation.weight_kg != null
            ? String(props.consultation.weight_kg)
            : '',
    height_cm:
        props.consultation.height_cm != null
            ? String(props.consultation.height_cm)
            : '',
    temperature_c:
        props.consultation.temperature_c != null
            ? String(props.consultation.temperature_c)
            : '',
    blood_pressure: props.consultation.blood_pressure ?? '',
    payment_amount:
        props.consultation.payment_amount != null
            ? String(props.consultation.payment_amount)
            : '',
    payment_method: props.consultation.payment_method ?? '',
    payment_service: props.consultation.payment_service ?? '',
});

const newPaymentReference = (): string => {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
        return crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-8xxx-xxxxxxxxxxxx'.replace(/[x]/g, () =>
        Math.floor(Math.random() * 16).toString(16),
    );
};

const paymentForm = useForm({
    amount:
        props.consultation.payment_amount != null
            ? String(props.consultation.payment_amount)
            : '',
    paid_today: '',
    method: props.consultation.payment_method ?? '',
    service: props.consultation.payment_service ?? '',
    notes: '',
    settlement: 'debt' as 'debt' | 'settled',
    client_reference: newPaymentReference(),
});

const projectedOutstanding = computed(() =>
    Math.max(
        0,
        Number(paymentForm.amount || 0) -
            props.consultation.payment_paid -
            Number(paymentForm.paid_today || 0),
    ),
);
const patientDebtUrl = computed(
    () =>
        `/app/payments?status=debt&search=${encodeURIComponent(props.patient.patient_number)}`,
);

const formatPaymentMoney = (amount: number): string =>
    new Intl.NumberFormat('fr-DZ', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(amount) + ' DA';

const isCompleted = computed(() => props.consultation.status === 'completed');

let patientAutosaveTimer: number | null = null;
let consultationAutosaveTimer: number | null = null;

const patientDraft = () => ({
    first_name: patientForm.first_name,
    last_name: patientForm.last_name,
    date_of_birth: patientForm.date_of_birth,
    gender: patientForm.gender,
    marital_status: patientForm.marital_status,
    profession: patientForm.profession,
    smoking_status: patientForm.smoking_status,
    referred_by: patientForm.referred_by,
    phone: patientForm.phone,
    email: patientForm.email,
    address: patientForm.address,
    city: patientForm.city,
    blood_group: patientForm.blood_group,
    allergies: patientForm.allergies,
    antecedents_medical: patientForm.antecedents_medical,
    antecedents_surgical: patientForm.antecedents_surgical,
    antecedents_family: patientForm.antecedents_family,
    antecedents_gyneco: patientForm.antecedents_gyneco,
    antecedents_other: patientForm.antecedents_other,
});

const consultationDraft = () => ({
    motif: consultationForm.motif,
    examens: consultationForm.examens,
    diagnostic: consultationForm.diagnostic,
    traitement: consultationForm.traitement,
    notes: consultationForm.notes,
    weight_kg: consultationForm.weight_kg,
    height_cm: consultationForm.height_cm,
    temperature_c: consultationForm.temperature_c,
    blood_pressure: consultationForm.blood_pressure,
    payment_amount: consultationForm.payment_amount,
    payment_method: consultationForm.payment_method,
    payment_service: consultationForm.payment_service,
});

const saveOfflineDraft = () => {
    const savedAt = new Date().toISOString();
    localStorage.setItem(
        offlineDraftKey,
        JSON.stringify({
            patient: patientDraft(),
            consultation: consultationDraft(),
            savedAt,
        }),
    );
    offlineDraftSavedAt.value = savedAt;
};

const applyOfflineDraft = () => {
    const raw = localStorage.getItem(offlineDraftKey);

    if (!raw) {
        return null;
    }

    try {
        const draft = JSON.parse(raw) as {
            patient?: Record<string, unknown>;
            consultation?: Record<string, unknown>;
            savedAt?: string;
        };
        Object.assign(patientForm, draft.patient ?? {});
        Object.assign(consultationForm, draft.consultation ?? {});
        offlineDraftSavedAt.value = draft.savedAt ?? null;

        return draft;
    } catch {
        localStorage.removeItem(offlineDraftKey);

        return null;
    }
};

const schedulePatientAutosave = () => {
    if (!props.canEdit) {
        return;
    }

    if (patientAutosaveTimer !== null) {
        window.clearTimeout(patientAutosaveTimer);
    }

    patientAutosaveTimer = window.setTimeout(() => {
        patientAutosaveTimer = null;

        if (!patientForm.processing) {
            savePatient();
        }
    }, 700);
};

const scheduleConsultationAutosave = () => {
    if (!props.canEdit) {
        return;
    }

    if (consultationAutosaveTimer !== null) {
        window.clearTimeout(consultationAutosaveTimer);
    }

    consultationAutosaveTimer = window.setTimeout(() => {
        consultationAutosaveTimer = null;

        if (!consultationForm.processing) {
            saveConsultation(false);
        }
    }, 700);
};

watch(
    () => ({
        first_name: patientForm.first_name,
        last_name: patientForm.last_name,
        date_of_birth: patientForm.date_of_birth,
        gender: patientForm.gender,
        marital_status: patientForm.marital_status,
        profession: patientForm.profession,
        smoking_status: patientForm.smoking_status,
        referred_by: patientForm.referred_by,
        phone: patientForm.phone,
        email: patientForm.email,
        address: patientForm.address,
        city: patientForm.city,
        blood_group: patientForm.blood_group,
        allergies: patientForm.allergies,
        antecedents_medical: patientForm.antecedents_medical,
        antecedents_surgical: patientForm.antecedents_surgical,
        antecedents_family: patientForm.antecedents_family,
        antecedents_gyneco: patientForm.antecedents_gyneco,
        antecedents_other: patientForm.antecedents_other,
    }),
    schedulePatientAutosave,
    { deep: true },
);

watch(
    () => ({
        motif: consultationForm.motif,
        examens: consultationForm.examens,
        diagnostic: consultationForm.diagnostic,
        traitement: consultationForm.traitement,
        notes: consultationForm.notes,
        weight_kg: consultationForm.weight_kg,
        height_cm: consultationForm.height_cm,
        temperature_c: consultationForm.temperature_c,
        blood_pressure: consultationForm.blood_pressure,
        payment_amount: consultationForm.payment_amount,
        payment_method: consultationForm.payment_method,
        payment_service: consultationForm.payment_service,
    }),
    scheduleConsultationAutosave,
    { deep: true },
);

const updateOnlineState = () => {
    isOnline.value = navigator.onLine;

    if (isOnline.value && localStorage.getItem(offlineDraftKey)) {
        const draft = applyOfflineDraft();
        let synced = 0;
        const markSynced = () => {
            synced += 1;

            if (synced === 2) {
                localStorage.removeItem(offlineDraftKey);
                offlineDraftSavedAt.value = null;
            }
        };

        if (draft) {
            patientForm.put(
                `/app/consultations/${props.consultation.id}/patient`,
                {
                    preserveScroll: true,
                    onSuccess: markSynced,
                },
            );
            consultationForm
                .transform((data) => ({ ...data, complete: false }))
                .put(`/app/consultations/${props.consultation.id}`, {
                    preserveScroll: true,
                    onSuccess: markSynced,
                });
        }
    }
};

onMounted(() => {
    applyOfflineDraft();
    window.addEventListener('online', updateOnlineState);
    window.addEventListener('offline', updateOnlineState);
});

onBeforeUnmount(() => {
    window.removeEventListener('online', updateOnlineState);
    window.removeEventListener('offline', updateOnlineState);

    if (patientAutosaveTimer !== null) {
        window.clearTimeout(patientAutosaveTimer);
    }

    if (consultationAutosaveTimer !== null) {
        window.clearTimeout(consultationAutosaveTimer);
    }
});

const savePatient = () => {
    if (!isOnline.value) {
        saveOfflineDraft();

        return;
    }

    patientForm.put(`/app/consultations/${props.consultation.id}/patient`, {
        preserveScroll: true,
    });
};
const saveConsultation = (complete: boolean) => {
    if (!isOnline.value) {
        saveOfflineDraft();

        return;
    }

    consultationForm
        .transform((data) => ({ ...data, complete }))
        .put(`/app/consultations/${props.consultation.id}`, {
            preserveScroll: true,
        });
};

const saveWorkspace = () => {
    if (!isOnline.value) {
        saveOfflineDraft();

        return;
    }

    savePatient();
    saveConsultation(false);
};

const confirmConsultation = () => {
    if (!isCompleted.value) {
        saveConsultation(true);
    }
};

const selectPaymentService = (value: string) => {
    consultationForm.payment_service = value;
    paymentForm.service = value;
    const prestation = props.prestations.find((item) => item.label === value);

    if (prestation?.amount != null) {
        consultationForm.payment_amount = String(prestation.amount);
        paymentForm.amount = String(prestation.amount);
    }
};

const collectPayment = () => {
    if (!isOnline.value || !props.canCollectPayment) {
        return;
    }

    paymentForm.post(`/app/consultations/${props.consultation.id}/payments`, {
        preserveScroll: true,
        onSuccess: () => {
            paymentForm.paid_today = '';
            paymentForm.notes = '';
            paymentForm.settlement = 'debt';
            paymentForm.client_reference = newPaymentReference();
        },
    });
};

const openQuickSection = (section: SectionKey) => {
    active.value = section;
    showQuickActions.value = false;
};

const age = computed(() => {
    if (!props.patient.date_of_birth) {
        return null;
    }

    const birth = new Date(`${props.patient.date_of_birth}T00:00:00`);

    return Math.floor(
        (Date.now() - birth.getTime()) / (365.25 * 24 * 3600 * 1000),
    );
});

const statusLabel = (status: string): string =>
    status.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());

const displayDate = (date: string | null): string => {
    if (!date) {
        return '—';
    }

    const [year, month, day] = date.slice(0, 10).split('-');

    return year && month && day ? day + '/' + month + '/' + year : date;
};

const patientHistoryFields = computed(() =>
    [
        { label: 'N° patient', value: props.patient.patient_number },
        { label: 'Nom complet', value: props.patient.full_name },
        {
            label: 'Date de naissance',
            value: props.patient.date_of_birth
                ? displayDate(props.patient.date_of_birth)
                : null,
        },
        {
            label: 'Sexe',
            value: props.patient.gender
                ? statusLabel(props.patient.gender)
                : null,
        },
        { label: 'Téléphone', value: props.patient.phone },
        { label: 'Email', value: props.patient.email },
        { label: 'Adresse', value: props.patient.address },
        { label: 'Ville', value: props.patient.city },
        { label: 'Groupe sanguin', value: props.patient.blood_group },
        { label: 'Situation familiale', value: props.patient.marital_status },
        { label: 'Profession', value: props.patient.profession },
        { label: 'Tabagisme', value: props.patient.smoking_status },
        { label: 'Orienté par', value: props.patient.referred_by },
        { label: 'Allergies', value: props.patient.allergies },
        {
            label: 'Antécédents médicaux',
            value: props.patient.antecedents_medical,
        },
        {
            label: 'Antécédents chirurgicaux',
            value: props.patient.antecedents_surgical,
        },
        {
            label: 'Antécédents familiaux',
            value: props.patient.antecedents_family,
        },
        {
            label: 'Antécédents gynéco-obstétricaux',
            value: props.patient.antecedents_gyneco,
        },
        {
            label: 'Autres antécédents',
            value: props.patient.antecedents_other,
        },
    ].filter(
        (field): field is { label: string; value: string } =>
            field.value !== null &&
            field.value !== undefined &&
            String(field.value).trim() !== '',
    ),
);

const consultationHistoryFields = computed(() =>
    [
        { label: 'Motif de visite', value: props.consultation.motif },
        { label: 'Examens', value: props.consultation.examens },
        { label: 'Diagnostic', value: props.consultation.diagnostic },
        { label: 'Traitement', value: props.consultation.traitement },
        { label: 'Notes internes', value: props.consultation.notes },
        {
            label: 'Poids',
            value:
                props.consultation.weight_kg !== null &&
                props.consultation.weight_kg !== ''
                    ? String(props.consultation.weight_kg) + ' kg'
                    : null,
        },
        {
            label: 'Taille',
            value:
                props.consultation.height_cm !== null &&
                props.consultation.height_cm !== ''
                    ? String(props.consultation.height_cm) + ' cm'
                    : null,
        },
        {
            label: 'Température',
            value:
                props.consultation.temperature_c !== null &&
                props.consultation.temperature_c !== ''
                    ? String(props.consultation.temperature_c) + ' °C'
                    : null,
        },
        {
            label: 'Tension artérielle',
            value: props.consultation.blood_pressure,
        },
    ].filter(
        (field): field is { label: string; value: string } =>
            field.value !== null &&
            field.value !== undefined &&
            String(field.value).trim() !== '',
    ),
);

const cardClass = 'med-panel p-4';
const headerCardClass = 'med-panel px-4 py-2.5';
const initials = computed(
    () =>
        `${props.patient.first_name?.[0] ?? ''}${
            props.patient.last_name?.[0] ?? ''
        }`.toUpperCase() || '—',
);
const tabClass = (activeTab: boolean): string =>
    `relative px-3 py-2 text-sm font-medium transition ${activeTab ? 'text-foreground' : 'text-muted-foreground hover:text-foreground'}`;
</script>

<template>
    <Head :title="`Consultation · ${patient.full_name}`" />

    <div class="med-page flex-col gap-4 lg:flex-row">
        <aside class="med-panel flex w-full shrink-0 flex-col p-3 lg:w-60">
            <Button
                variant="outline"
                size="sm"
                as-child
                class="mb-3 justify-start"
            >
                <Link href="/app/consultations"
                    ><ArrowLeft class="size-4" /> Retour aux consultations du
                    jour</Link
                >
            </Button>

            <nav
                class="grid grid-cols-2 gap-1 sm:grid-cols-3 lg:flex lg:flex-col"
            >
                <button
                    v-for="item in orderedSections"
                    :key="item.key"
                    type="button"
                    class="flex items-center gap-3 rounded-lg px-3 py-2 text-left transition"
                    :class="
                        active === item.key
                            ? 'bg-brand text-white shadow-sm'
                            : 'text-foreground hover:bg-accent'
                    "
                    @click="active = item.key"
                >
                    <component :is="item.icon" class="size-4 shrink-0" />
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-medium">{{
                            item.label
                        }}</span>
                        <span
                            class="block truncate text-[11px]"
                            :class="
                                active === item.key
                                    ? 'text-primary-foreground/80'
                                    : 'text-muted-foreground'
                            "
                            >{{ item.sub }}</span
                        >
                    </span>
                </button>
            </nav>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col gap-4">
            <div
                :class="[
                    headerCardClass,
                    'flex shrink-0 flex-wrap items-center gap-3',
                ]"
            >
                <div
                    class="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary"
                >
                    {{ initials }}
                </div>
                <div class="min-w-0">
                    <h2
                        class="truncate text-base font-semibold text-foreground"
                    >
                        {{ patient.full_name }}
                    </h2>
                    <p class="truncate text-xs text-muted-foreground">
                        <span class="font-mono">{{
                            patient.patient_number
                        }}</span>
                        <template v-if="age !== null">
                            · {{ age }} yrs</template
                        >
                        <template v-if="patient.gender">
                            · {{ statusLabel(patient.gender) }}</template
                        >
                        <template v-if="patient.blood_group">
                            · {{ patient.blood_group }}</template
                        >
                    </p>
                </div>
                <div
                    class="ml-auto flex flex-wrap items-center justify-end gap-2"
                >
                    <div
                        v-if="active === 'ordonnances'"
                        class="flex items-center gap-2"
                    >
                        <Label
                            for="workspace-ordonnance-model"
                            class="hidden text-xs text-muted-foreground sm:inline"
                        >
                            Modèle
                        </Label>
                        <select
                            id="workspace-ordonnance-model"
                            v-model="ordonnanceTemplateId"
                            class="med-native-control max-w-52 text-xs font-medium"
                        >
                            <option
                                v-for="template in ordonnanceTemplates"
                                :key="`${template.source}:${template.key}`"
                                :value="`${template.source}:${template.key}`"
                            >
                                {{ template.title }}
                            </option>
                        </select>
                        <label
                            class="flex items-center gap-1.5 text-xs text-muted-foreground"
                        >
                            <span class="hidden lg:inline">Date</span>
                            <input
                                v-model="ordonnanceDate"
                                type="date"
                                class="h-9 rounded-md border border-input bg-background px-2 text-xs font-medium text-foreground"
                                :disabled="!canEdit"
                            />
                        </label>
                        <label
                            class="flex items-center gap-1.5 text-xs text-muted-foreground"
                        >
                            <input
                                v-model="ordonnanceShowDate"
                                type="checkbox"
                                class="size-3.5 accent-primary"
                                :disabled="!canEdit"
                            />
                            <span class="hidden sm:inline"
                                >Afficher la date</span
                            >
                        </label>
                    </div>
                    <div
                        v-if="canEdit"
                        class="flex flex-wrap items-center gap-2"
                    >
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="
                                patientForm.processing ||
                                consultationForm.processing
                            "
                            @click="saveWorkspace"
                        >
                            <Save class="size-4" />
                            Enregistrer
                        </Button>
                        <Button
                            size="sm"
                            :disabled="
                                isCompleted || consultationForm.processing
                            "
                            @click="confirmConsultation"
                        >
                            <Check class="size-4" />
                            {{
                                isCompleted
                                    ? 'Consultation confirmée'
                                    : 'Confirmer la consultation'
                            }}
                        </Button>
                    </div>
                    <span
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                        :class="
                            isCompleted
                                ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
                                : 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300'
                        "
                    >
                        <span
                            class="size-1.5 rounded-full"
                            :class="
                                isCompleted ? 'bg-emerald-500' : 'bg-amber-500'
                            "
                        />
                        {{ statusLabel(consultation.status) }}
                    </span>
                    <span
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                        :class="
                            isOnline
                                ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
                                : 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300'
                        "
                        :title="
                            offlineDraftSavedAt
                                ? 'Les modifications sont enregistrées sur cet appareil et seront synchronisées au retour de la connexion.'
                                : undefined
                        "
                    >
                        <Cloud v-if="isOnline" class="size-3.5" />
                        <CloudOff v-else class="size-3.5" />
                        {{
                            isOnline
                                ? 'En ligne'
                                : 'Hors ligne · enregistré localement'
                        }}
                    </span>
                </div>
            </div>

            <div class="min-h-0 flex-1 overflow-x-hidden overflow-y-auto">
                <!-- DOSSIER -->
                <div
                    v-if="active === 'dossier'"
                    class="grid min-h-0 min-w-0 gap-4 xl:h-full xl:grid-cols-[16rem_minmax(0,1fr)_minmax(0,1.05fr)] xl:overflow-hidden"
                >
                    <!-- Column 1 · important note + quick access -->
                    <div class="flex min-h-0 min-w-0 flex-col gap-3">
                        <div
                            :class="[cardClass, 'flex min-w-0 flex-col gap-3']"
                            data-testid="consultation-important-note"
                        >
                            <p
                                class="flex items-center gap-1.5 text-sm font-semibold text-red-600 dark:text-red-400"
                            >
                                <TriangleAlert class="size-4" /> Important à
                                signaler
                            </p>
                            <Textarea
                                v-model="patientForm.allergies"
                                :disabled="!canEdit"
                                placeholder="Notes importantes, allergies…"
                                rows="6"
                                class="h-36 max-h-56 min-h-28 w-full resize-y overflow-y-auto"
                            />
                        </div>
                        <div
                            class="grid min-w-0 shrink-0 grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-2"
                            data-testid="consultation-document-shortcuts"
                        >
                            <Button
                                variant="outline"
                                size="sm"
                                class="h-auto min-w-0 flex-col gap-1 px-2 py-2.5"
                                @click="active = 'ordonnances'"
                                ><Pill class="size-4" /><span
                                    class="w-full text-center text-[11px] leading-tight"
                                    >Ordonnances</span
                                ></Button
                            >
                            <Button
                                variant="outline"
                                size="sm"
                                class="h-auto min-w-0 flex-col gap-1 px-2 py-2.5"
                                @click="active = 'bilans'"
                                ><FlaskConical class="size-4" /><span
                                    class="w-full text-center text-[11px] leading-tight"
                                    >Bilans</span
                                ></Button
                            >
                            <Button
                                variant="outline"
                                size="sm"
                                class="h-auto min-w-0 flex-col gap-1 px-2 py-2.5"
                                @click="active = 'courriers'"
                                ><Mail class="size-4" /><span
                                    class="w-full text-center text-[11px] leading-tight"
                                    >Courriers</span
                                ></Button
                            >
                            <Button
                                variant="outline"
                                size="sm"
                                class="h-auto min-w-0 flex-col gap-1 px-2 py-2.5"
                                @click="active = 'documents'"
                                ><FileText class="size-4" /><span
                                    class="w-full text-center text-[11px] leading-tight"
                                    >Documents</span
                                ></Button
                            >
                        </div>
                    </div>

                    <!-- Column 2 · dossier (état civil / antécédents) -->
                    <div :class="[cardClass, 'flex min-h-0 flex-col !p-0']">
                        <div
                            class="flex shrink-0 gap-2 border-b border-sidebar-border/70 px-4 pt-3 dark:border-sidebar-border"
                        >
                            <button
                                type="button"
                                :class="tabClass(dossierTab === 'etat_civil')"
                                @click="dossierTab = 'etat_civil'"
                            >
                                État civil<span
                                    v-if="dossierTab === 'etat_civil'"
                                    class="absolute inset-x-0 -bottom-px block h-0.5 bg-primary"
                                />
                            </button>
                            <button
                                type="button"
                                :class="tabClass(dossierTab === 'antecedents')"
                                @click="dossierTab = 'antecedents'"
                            >
                                Antécédents<span
                                    v-if="dossierTab === 'antecedents'"
                                    class="absolute inset-x-0 -bottom-px block h-0.5 bg-primary"
                                />
                            </button>
                        </div>

                        <div class="min-h-0 flex-1 overflow-y-auto p-4">
                            <div
                                v-if="dossierTab === 'etat_civil'"
                                class="grid gap-3 sm:grid-cols-2"
                            >
                                <div class="grid gap-1.5">
                                    <Label>ID</Label
                                    ><Input
                                        :model-value="patient.patient_number"
                                        disabled
                                        class="font-mono"
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="last_name">Nom</Label
                                    ><Input
                                        id="last_name"
                                        v-model="patientForm.last_name"
                                        :disabled="!canEdit"
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="first_name">Prénom</Label
                                    ><Input
                                        id="first_name"
                                        v-model="patientForm.first_name"
                                        :disabled="!canEdit"
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="dob">D.D.N</Label
                                    ><Input
                                        id="dob"
                                        v-model="patientForm.date_of_birth"
                                        type="date"
                                        :disabled="!canEdit"
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label>Sexe</Label>
                                    <Select
                                        :model-value="patientForm.gender"
                                        @update:model-value="
                                            (v) =>
                                                (patientForm.gender =
                                                    (v as string) ?? '')
                                        "
                                    >
                                        <SelectTrigger
                                            class="w-full"
                                            :disabled="!canEdit"
                                            ><SelectValue placeholder="—"
                                        /></SelectTrigger>
                                        <SelectContent
                                            ><SelectItem
                                                v-for="o in options.genders"
                                                :key="o.value"
                                                :value="o.value"
                                                >{{ o.label }}</SelectItem
                                            ></SelectContent
                                        >
                                    </Select>
                                </div>
                                <div class="grid gap-1.5">
                                    <Label>Groupage</Label>
                                    <Select
                                        :model-value="patientForm.blood_group"
                                        @update:model-value="
                                            (v) =>
                                                (patientForm.blood_group =
                                                    (v as string) ?? '')
                                        "
                                    >
                                        <SelectTrigger
                                            class="w-full"
                                            :disabled="!canEdit"
                                            ><SelectValue placeholder="—"
                                        /></SelectTrigger>
                                        <SelectContent
                                            ><SelectItem
                                                v-for="o in options.bloodGroups"
                                                :key="o.value"
                                                :value="o.value"
                                                >{{ o.label }}</SelectItem
                                            ></SelectContent
                                        >
                                    </Select>
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="phone">Téléphone</Label
                                    ><Input
                                        id="phone"
                                        v-model="patientForm.phone"
                                        :disabled="!canEdit"
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label>Tabagisme</Label>
                                    <Select
                                        :model-value="
                                            patientForm.smoking_status
                                        "
                                        @update:model-value="
                                            (v) =>
                                                (patientForm.smoking_status =
                                                    (v as string) ?? '')
                                        "
                                    >
                                        <SelectTrigger
                                            class="w-full"
                                            :disabled="!canEdit"
                                            ><SelectValue placeholder="—"
                                        /></SelectTrigger>
                                        <SelectContent
                                            ><SelectItem
                                                v-for="o in options.smokingStatuses"
                                                :key="o.value"
                                                :value="o.value"
                                                >{{ o.label }}</SelectItem
                                            ></SelectContent
                                        >
                                    </Select>
                                </div>
                                <div class="grid gap-1.5">
                                    <Label>Sit. familiale</Label>
                                    <Select
                                        :model-value="
                                            patientForm.marital_status
                                        "
                                        @update:model-value="
                                            (v) =>
                                                (patientForm.marital_status =
                                                    (v as string) ?? '')
                                        "
                                    >
                                        <SelectTrigger
                                            class="w-full"
                                            :disabled="!canEdit"
                                            ><SelectValue placeholder="—"
                                        /></SelectTrigger>
                                        <SelectContent
                                            ><SelectItem
                                                v-for="o in options.maritalStatuses"
                                                :key="o.value"
                                                :value="o.value"
                                                >{{ o.label }}</SelectItem
                                            ></SelectContent
                                        >
                                    </Select>
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="profession">Profession</Label
                                    ><Input
                                        id="profession"
                                        v-model="patientForm.profession"
                                        :disabled="!canEdit"
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="referred_by">Orienté par</Label
                                    ><Input
                                        id="referred_by"
                                        v-model="patientForm.referred_by"
                                        :disabled="!canEdit"
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="email">Email</Label
                                    ><Input
                                        id="email"
                                        v-model="patientForm.email"
                                        type="email"
                                        :disabled="!canEdit"
                                    />
                                </div>
                                <div class="grid gap-1.5 sm:col-span-2">
                                    <Label for="address">Adresse</Label
                                    ><Input
                                        id="address"
                                        v-model="patientForm.address"
                                        :disabled="!canEdit"
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="city">Ville</Label
                                    ><Input
                                        id="city"
                                        v-model="patientForm.city"
                                        :disabled="!canEdit"
                                    />
                                </div>
                            </div>

                            <div v-else class="grid gap-4 md:grid-cols-2">
                                <div class="grid gap-1.5">
                                    <Label>Antécédents médicaux</Label
                                    ><Textarea
                                        v-model="
                                            patientForm.antecedents_medical
                                        "
                                        rows="4"
                                        :disabled="!canEdit"
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label>Antécédents chirurgicaux</Label
                                    ><Textarea
                                        v-model="
                                            patientForm.antecedents_surgical
                                        "
                                        rows="4"
                                        :disabled="!canEdit"
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label>Antécédents familiaux</Label
                                    ><Textarea
                                        v-model="patientForm.antecedents_family"
                                        rows="4"
                                        :disabled="!canEdit"
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label
                                        >Antécédents gynéco-obstétricaux</Label
                                    ><Textarea
                                        v-model="patientForm.antecedents_gyneco"
                                        rows="4"
                                        :disabled="!canEdit"
                                    />
                                </div>
                                <div class="grid gap-1.5 md:col-span-2">
                                    <Label>Autres antécédents</Label
                                    ><Textarea
                                        v-model="patientForm.antecedents_other"
                                        rows="3"
                                        :disabled="!canEdit"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Column 3 · visite médicale -->
                    <div :class="[cardClass, 'flex min-h-0 flex-col !p-0']">
                        <h3
                            class="flex shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-4 py-3 text-sm font-semibold text-foreground dark:border-sidebar-border"
                        >
                            <Stethoscope class="size-4 text-primary" /> Visite
                            médicale
                        </h3>
                        <div class="min-h-0 flex-1 overflow-y-auto p-4">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="grid gap-1.5">
                                    <Label
                                        class="text-brand dark:text-brand-mint"
                                        >Motif de visite</Label
                                    ><Textarea
                                        v-model="consultationForm.motif"
                                        rows="5"
                                        :disabled="!canEdit"
                                        placeholder="Saisir motif de visite…"
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label
                                        class="text-emerald-700 dark:text-emerald-300"
                                        >Examens</Label
                                    ><Textarea
                                        v-model="consultationForm.examens"
                                        rows="5"
                                        :disabled="!canEdit"
                                        placeholder="Saisir examens…"
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label
                                        class="text-amber-700 dark:text-amber-300"
                                        >Diagnostic</Label
                                    ><Textarea
                                        v-model="consultationForm.diagnostic"
                                        rows="5"
                                        :disabled="!canEdit"
                                        placeholder="Saisir diagnostic…"
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label
                                        class="text-brand dark:text-brand-mint"
                                        >Traitement</Label
                                    ><Textarea
                                        v-model="consultationForm.traitement"
                                        rows="5"
                                        :disabled="!canEdit"
                                        placeholder="Saisir traitement…"
                                    />
                                </div>
                                <div class="grid gap-1.5 md:col-span-2">
                                    <Label>Notes internes</Label
                                    ><Textarea
                                        v-model="consultationForm.notes"
                                        rows="3"
                                        :disabled="!canEdit"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <RendezVousPanel
                    v-else-if="active === 'rendezvous'"
                    :consultation-id="consultation.id"
                    :upcoming="upcoming"
                    :past="past"
                    :prestations="prestations"
                    :can-edit="canEdit"
                />

                <CourbesPanel
                    v-else-if="active === 'courbes'"
                    :consultation-id="consultation.id"
                    :measurements="measurements"
                    :can-edit="canEdit"
                />

                <OrdonnancesPanel
                    v-else-if="active === 'ordonnances'"
                    :consultation-id="consultation.id"
                    :prescriptions="prescriptions"
                    :medications="medications"
                    :templates="documentTemplates"
                    v-model:model-template-id="ordonnanceTemplateId"
                    v-model:prescription-date="ordonnanceDate"
                    v-model:show-prescription-date="ordonnanceShowDate"
                    :patient="patient"
                    :cabinet="cabinet"
                    :can-edit="canEdit"
                />

                <BilansPanel
                    v-else-if="active === 'bilans'"
                    :consultation-id="consultation.id"
                    :exams="exams"
                    :bilan-categories="bilanCategories"
                    :documents="documents"
                    :patient="patient"
                    :cabinet="cabinet"
                    :can-edit="canEdit"
                />

                <CourriersPanel
                    v-else-if="active === 'courriers'"
                    :consultation-id="consultation.id"
                    :templates="documentTemplates"
                    :documents="documents"
                    :patient="patient"
                    :consultation="consultation"
                    :cabinet="cabinet"
                    :can-edit="canEdit"
                />

                <DocumentsPanel
                    v-else-if="active === 'documents'"
                    :consultation-id="consultation.id"
                    :files="uploadedFiles"
                    :can-edit="canEdit"
                />

                <!-- Caisse : charge + immutable installments + patient debt -->
                <section
                    v-else-if="active === 'caisse'"
                    :class="[cardClass, 'space-y-5']"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div>
                            <h3 class="text-base font-semibold text-foreground">
                                Caisse — cette consultation
                            </h3>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Chaque versement est conservé. Le prix de la
                                prestation et le reste dû ne sont jamais
                                écrasés.
                            </p>
                        </div>
                        <Button
                            variant="secondary"
                            size="sm"
                            as-child
                            class="bg-slate-200 text-slate-800 hover:bg-slate-300 dark:bg-slate-700 dark:text-slate-100"
                        >
                            <Link :href="patientDebtUrl">
                                <Wallet class="size-4" />
                                Dettes du patient
                                <span
                                    v-if="patientDebt.total > 0"
                                    class="rounded-full bg-white/80 px-2 py-0.5 text-xs text-slate-900 tabular-nums"
                                >
                                    {{ formatPaymentMoney(patientDebt.total) }}
                                </span>
                            </Link>
                        </Button>
                    </div>

                    <div
                        v-if="patientDebt.total > 0"
                        class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100"
                    >
                        <div class="flex items-start gap-3">
                            <TriangleAlert class="mt-0.5 size-5 shrink-0" />
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold">
                                    Dette antérieure :
                                    {{ formatPaymentMoney(patientDebt.total) }}
                                </p>
                                <p class="mt-1 text-sm opacity-80">
                                    Encaissez d’abord le reste des anciennes
                                    prestations, puis enregistrez la prestation
                                    d’aujourd’hui séparément.
                                </p>
                                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                    <div
                                        v-for="debt in patientDebt.consultations"
                                        :key="debt.id"
                                        class="rounded-lg border border-amber-200 bg-white/70 px-3 py-2 text-sm dark:border-amber-900 dark:bg-slate-950/30"
                                    >
                                        <p class="font-medium">
                                            {{ debt.service }}
                                        </p>
                                        <p class="mt-0.5 text-xs opacity-75">
                                            {{ displayDate(debt.date) }} · versé
                                            {{ formatPaymentMoney(debt.paid) }}
                                            · reste
                                            <strong>
                                                {{
                                                    formatPaymentMoney(
                                                        debt.outstanding,
                                                    )
                                                }}
                                            </strong>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="rounded-xl border bg-muted/25 p-4">
                            <p
                                class="text-xs font-medium text-muted-foreground"
                            >
                                Prix de la prestation
                            </p>
                            <p class="mt-1 text-xl font-bold tabular-nums">
                                {{
                                    formatPaymentMoney(
                                        Number(paymentForm.amount || 0),
                                    )
                                }}
                            </p>
                        </div>
                        <div class="rounded-xl border bg-muted/25 p-4">
                            <p
                                class="text-xs font-medium text-muted-foreground"
                            >
                                Déjà encaissé
                            </p>
                            <p
                                class="mt-1 text-xl font-bold text-brand tabular-nums"
                            >
                                {{
                                    formatPaymentMoney(
                                        consultation.payment_paid,
                                    )
                                }}
                            </p>
                        </div>
                        <div class="rounded-xl border bg-muted/25 p-4">
                            <p
                                class="text-xs font-medium text-muted-foreground"
                            >
                                Reste après ce versement
                            </p>
                            <p
                                class="mt-1 text-xl font-bold tabular-nums"
                                :class="
                                    projectedOutstanding > 0
                                        ? 'text-amber-700 dark:text-amber-300'
                                        : 'text-emerald-700 dark:text-emerald-300'
                                "
                            >
                                {{ formatPaymentMoney(projectedOutstanding) }}
                            </p>
                        </div>
                    </div>

                    <form class="space-y-4" @submit.prevent="collectPayment">
                        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <div class="grid gap-1.5 xl:col-span-2">
                                <Label>Prestation du jour</Label>
                                <Select
                                    :model-value="
                                        paymentForm.service || undefined
                                    "
                                    @update:model-value="
                                        (v) => selectPaymentService(v as string)
                                    "
                                >
                                    <SelectTrigger
                                        class="w-full"
                                        :disabled="!canCollectPayment"
                                    >
                                        <SelectValue
                                            placeholder="Choisir une prestation"
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="prestation in prestations"
                                            :key="prestation.label"
                                            :value="prestation.label"
                                        >
                                            {{ prestation.label }}
                                            <template
                                                v-if="prestation.amount != null"
                                            >
                                                — {{ prestation.amount }} DA
                                            </template>
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <Input
                                    v-model="paymentForm.service"
                                    :disabled="!canCollectPayment"
                                    placeholder="Ou saisir une prestation"
                                />
                                <InputError
                                    :message="paymentForm.errors.service"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="c-amount">Prix total (DA)</Label>
                                <Input
                                    id="c-amount"
                                    v-model="paymentForm.amount"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    :disabled="!canCollectPayment"
                                />
                                <InputError
                                    :message="paymentForm.errors.amount"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="c-paid-today"
                                    >Versé aujourd’hui (DA)</Label
                                >
                                <Input
                                    id="c-paid-today"
                                    v-model="paymentForm.paid_today"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    :max="
                                        Math.max(
                                            0,
                                            Number(paymentForm.amount || 0) -
                                                consultation.payment_paid,
                                        )
                                    "
                                    :disabled="!canCollectPayment"
                                />
                                <InputError
                                    :message="paymentForm.errors.paid_today"
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label>Moyen de paiement</Label>
                                <Select
                                    :model-value="
                                        paymentForm.method || undefined
                                    "
                                    @update:model-value="
                                        (v) =>
                                            (paymentForm.method =
                                                (v as string) ?? '')
                                    "
                                >
                                    <SelectTrigger
                                        class="w-full"
                                        :disabled="!canCollectPayment"
                                    >
                                        <SelectValue placeholder="Choisir" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="method in options.paymentMethods"
                                            :key="method"
                                            :value="method"
                                        >
                                            {{ method }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError
                                    :message="paymentForm.errors.method"
                                />
                            </div>
                            <div
                                class="grid gap-1.5 sm:col-span-1 xl:col-span-3"
                            >
                                <Label>Traitement du solde</Label>
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
                                            :disabled="!canCollectPayment"
                                        />
                                        <span>
                                            <span
                                                class="block text-sm font-semibold"
                                                >Garder le reste en dette</span
                                            >
                                            <span
                                                class="text-xs text-muted-foreground"
                                                >Le patient pourra payer plus
                                                tard.</span
                                            >
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
                                            :disabled="!canCollectPayment"
                                        />
                                        <span>
                                            <span
                                                class="block text-sm font-semibold"
                                                >Accepter moins et solder</span
                                            >
                                            <span
                                                class="text-xs text-muted-foreground"
                                                >Le reliquat sera enregistré
                                                comme remise.</span
                                            >
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="payment-notes">
                                Note
                                <span
                                    v-if="
                                        paymentForm.settlement === 'settled' &&
                                        projectedOutstanding > 0
                                    "
                                    class="text-destructive"
                                >
                                    (obligatoire pour une remise)
                                </span>
                            </Label>
                            <Textarea
                                id="payment-notes"
                                v-model="paymentForm.notes"
                                :disabled="!canCollectPayment"
                                placeholder="Détail du versement, motif de remise ou accord avec le patient…"
                            />
                            <InputError :message="paymentForm.errors.notes" />
                        </div>

                        <div
                            class="flex flex-wrap items-center justify-between gap-3"
                        >
                            <p v-if="!isOnline" class="text-sm text-amber-700">
                                La connexion au serveur est requise pour
                                enregistrer un mouvement de caisse sans doublon.
                            </p>
                            <span v-else />
                            <Button
                                type="submit"
                                :disabled="
                                    !canCollectPayment ||
                                    !isOnline ||
                                    paymentForm.processing
                                "
                            >
                                <Wallet class="size-4" />
                                Enregistrer le versement
                            </Button>
                        </div>
                    </form>

                    <div
                        v-if="consultation.payments.length"
                        class="border-t pt-4"
                    >
                        <h4 class="text-sm font-semibold">
                            Historique des versements
                        </h4>
                        <div class="med-table-wrap mt-3">
                            <table class="med-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Montant</th>
                                        <th>Mode</th>
                                        <th>Enregistré par</th>
                                        <th>Note</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="installment in consultation.payments"
                                        :key="installment.id"
                                    >
                                        <td>
                                            {{
                                                displayDate(
                                                    installment.received_at,
                                                )
                                            }}
                                        </td>
                                        <td class="font-semibold tabular-nums">
                                            {{
                                                formatPaymentMoney(
                                                    installment.amount,
                                                )
                                            }}
                                        </td>
                                        <td>{{ installment.method || '—' }}</td>
                                        <td>
                                            {{ installment.received_by || '—' }}
                                        </td>
                                        <td class="max-w-64">
                                            {{ installment.notes || '—' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <!-- Historique -->
                <section
                    v-else-if="active === 'historique'"
                    :class="[cardClass, 'space-y-4']"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div>
                            <h3
                                class="flex items-center gap-2 text-base font-semibold text-foreground"
                            >
                                <Clock class="size-5 text-primary" />
                                Clinical history
                            </h3>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Informations remplies dans le dossier et la
                                consultation actuelle.
                            </p>
                        </div>
                        <span
                            class="rounded-full bg-muted px-3 py-1 text-xs font-medium text-muted-foreground"
                        >
                            {{ displayDate(consultation.consulted_at) }}
                        </span>
                    </div>

                    <div class="grid gap-4 xl:grid-cols-2">
                        <article
                            class="rounded-xl border border-sidebar-border/70 bg-muted/20 p-4 dark:border-sidebar-border"
                        >
                            <h4
                                class="flex items-center gap-2 text-sm font-semibold text-foreground"
                            >
                                <FolderOpen class="size-4 text-primary" />
                                Dossier patient
                            </h4>
                            <dl
                                v-if="patientHistoryFields.length"
                                class="mt-4 grid gap-2 sm:grid-cols-2"
                            >
                                <div
                                    v-for="field in patientHistoryFields"
                                    :key="field.label"
                                    class="rounded-lg border border-sidebar-border/70 bg-background px-3 py-2.5 dark:border-sidebar-border"
                                >
                                    <dt
                                        class="text-[11px] font-medium text-muted-foreground"
                                    >
                                        {{ field.label }}
                                    </dt>
                                    <dd
                                        class="mt-1 text-sm font-medium break-words whitespace-pre-line text-foreground"
                                    >
                                        {{ field.value }}
                                    </dd>
                                </div>
                            </dl>
                            <p
                                v-else
                                class="mt-4 text-sm text-muted-foreground"
                            >
                                Aucun champ patient rempli.
                            </p>
                        </article>

                        <article
                            class="rounded-xl border border-sidebar-border/70 bg-muted/20 p-4 dark:border-sidebar-border"
                        >
                            <h4
                                class="flex items-center gap-2 text-sm font-semibold text-foreground"
                            >
                                <Stethoscope class="size-4 text-emerald-600" />
                                Visite médicale
                            </h4>
                            <dl
                                v-if="consultationHistoryFields.length"
                                class="mt-4 grid gap-2"
                            >
                                <div
                                    v-for="field in consultationHistoryFields"
                                    :key="field.label"
                                    class="rounded-lg border border-sidebar-border/70 bg-background px-3 py-2.5 dark:border-sidebar-border"
                                >
                                    <dt
                                        class="text-[11px] font-medium text-muted-foreground"
                                    >
                                        {{ field.label }}
                                    </dt>
                                    <dd
                                        class="mt-1 text-sm font-medium break-words whitespace-pre-line text-foreground"
                                    >
                                        {{ field.value }}
                                    </dd>
                                </div>
                            </dl>
                            <p
                                v-else
                                class="mt-4 text-sm text-muted-foreground"
                            >
                                Aucun champ de visite rempli.
                            </p>
                        </article>
                    </div>

                    <article
                        class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h4
                                    class="text-sm font-semibold text-foreground"
                                >
                                    Consultations précédentes
                                </h4>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ history.length }} consultation{{
                                        history.length === 1 ? '' : 's'
                                    }}
                                </p>
                            </div>
                            <Clock class="size-4 text-muted-foreground" />
                        </div>
                        <ul
                            v-if="history.length"
                            class="mt-4 grid gap-2 lg:grid-cols-2"
                        >
                            <li
                                v-for="item in history"
                                :key="item.id"
                                class="rounded-lg border border-sidebar-border/70 bg-muted/20 px-4 py-3 text-sm dark:border-sidebar-border"
                            >
                                <div
                                    class="flex items-center justify-between gap-3"
                                >
                                    <span class="font-medium text-foreground">{{
                                        item.consulted_at ?? '—'
                                    }}</span>
                                    <span
                                        class="text-xs text-muted-foreground capitalize"
                                        >{{ statusLabel(item.status) }}</span
                                    >
                                </div>
                                <p
                                    v-if="item.motif"
                                    class="mt-2 line-clamp-2 text-muted-foreground"
                                >
                                    <span class="font-medium">Motif:</span>
                                    {{ item.motif }}
                                </p>
                                <p
                                    v-if="item.diagnostic"
                                    class="mt-1 line-clamp-2 text-muted-foreground"
                                >
                                    <span class="font-medium">Diagnostic:</span>
                                    {{ item.diagnostic }}
                                </p>
                            </li>
                        </ul>
                        <p
                            v-else
                            class="mt-4 rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
                        >
                            Aucune consultation précédente pour ce patient.
                        </p>
                    </article>
                </section>

                <!-- Synthèse -->
                <section v-else-if="active === 'analytics'" :class="cardClass">
                    <h3 class="text-base font-semibold text-foreground">
                        Synthèse du patient
                    </h3>
                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <div
                            class="rounded-lg border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                        >
                            <p class="text-2xl font-semibold text-foreground">
                                {{ stats.consultations }}
                            </p>
                            <p class="text-sm text-muted-foreground">
                                Consultations
                            </p>
                        </div>
                        <div
                            class="rounded-lg border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                        >
                            <p class="text-2xl font-semibold text-foreground">
                                {{ stats.appointments }}
                            </p>
                            <p class="text-sm text-muted-foreground">
                                Rendez-vous
                            </p>
                        </div>
                        <div
                            class="rounded-lg border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                        >
                            <p
                                class="flex items-center gap-1 text-2xl font-semibold text-foreground"
                            >
                                <FileText class="size-5" />
                                {{ documents.length + uploadedFiles.length }}
                            </p>
                            <p class="text-sm text-muted-foreground">
                                Documents
                            </p>
                        </div>
                    </div>
                </section>

                <section
                    v-else
                    class="flex min-h-48 items-center justify-center rounded-xl border border-dashed border-sidebar-border/70 bg-background p-6 text-center dark:border-sidebar-border"
                >
                    <p class="text-sm text-muted-foreground">
                        Cette section sera disponible prochainement.
                    </p>
                </section>
            </div>
        </div>

        <div class="fixed right-5 bottom-5 z-40 flex flex-col items-end gap-2">
            <div
                v-if="showQuickActions"
                class="flex flex-col items-end gap-2 rounded-2xl border border-border bg-background/95 p-2 shadow-xl backdrop-blur"
            >
                <button
                    type="button"
                    class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold text-foreground transition hover:bg-muted"
                    @click="openQuickSection('rendezvous')"
                >
                    Rendez-vous
                    <span
                        class="flex size-9 items-center justify-center rounded-full bg-brand text-white"
                    >
                        <CalendarDays class="size-4" />
                    </span>
                </button>
                <button
                    type="button"
                    class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold text-foreground transition hover:bg-muted"
                    @click="openQuickSection('caisse')"
                >
                    Paiement
                    <span
                        class="flex size-9 items-center justify-center rounded-full bg-emerald-600 text-white"
                    >
                        <Wallet class="size-4" />
                    </span>
                </button>
                <button
                    type="button"
                    class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold text-foreground transition hover:bg-muted"
                    @click="openQuickSection('documents')"
                >
                    Documents
                    <span
                        class="flex size-9 items-center justify-center rounded-full bg-amber-500 text-white"
                    >
                        <FileText class="size-4" />
                    </span>
                </button>
                <button
                    v-if="canEdit"
                    type="button"
                    class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold text-foreground transition hover:bg-muted"
                    @click="
                        saveWorkspace();
                        showQuickActions = false;
                    "
                >
                    Enregistrer
                    <span
                        class="flex size-9 items-center justify-center rounded-full bg-slate-700 text-white"
                    >
                        <Save class="size-4" />
                    </span>
                </button>
            </div>
            <button
                type="button"
                class="flex size-14 items-center justify-center rounded-full bg-brand text-white shadow-lg shadow-brand-deep/25 transition hover:-translate-y-0.5 hover:bg-brand focus-visible:ring-4 focus-visible:ring-brand focus-visible:outline-none"
                :aria-expanded="showQuickActions"
                aria-label="Actions rapides"
                @click="showQuickActions = !showQuickActions"
            >
                <X v-if="showQuickActions" class="size-6" />
                <Plus v-else class="size-7" />
            </button>
        </div>
    </div>
</template>
